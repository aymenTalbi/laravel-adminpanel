<?php

namespace App\Repositories\Backend\Blogs;

use App\Events\Backend\Blogs\BlogCreated;
use App\Events\Backend\Blogs\BlogDeleted;
use App\Events\Backend\Blogs\BlogUpdated;
use App\Exceptions\GeneralException;
use App\Http\Utilities\FileUploads;
use App\Models\BlogMapCategories\BlogMapCategory;
use App\Models\BlogMapTags\BlogMapTag;
use App\Models\Blogs\Blog;
use App\Repositories\BaseRepository;
use Carbon\Carbon;
use DB;

/**
 * Class BlogsRepository.
 */
class BlogsRepository extends BaseRepository
{
    /**
     * Associated Repository Model.
     */
    const MODEL = Blog::class;

    /**
     * @return mixed
     */
    public function getForDataTable()
    {
        return $this->query()
            ->leftjoin(config('access.users_table'), config('access.users_table').'.id', '=', config('access.blogs_table').'.created_by')
            ->select([
                config('access.blogs_table').'.id',
                config('access.blogs_table').'.name',
                config('access.blogs_table').'.publish_datetime',
                config('access.blogs_table').'.status',
                config('access.blogs_table').'.created_by',
                config('access.blogs_table').'.created_at',
                config('access.users_table').'.first_name as user_name',
            ]);
    }

    /**
     * @param array $input
     *
     * @throws GeneralException
     *
     * @return bool
     */
    public function create(array $input)
    {
        $tagsArray = $this->createTagsArray($input['tags']);
        $categoriesArray = $this->createCategoriesArray($input['categories']);

        DB::transaction(function () use ($input, $tagsArray, $categoriesArray) {
            $blogs = self::MODEL;
            $blogs = new $blogs();
            $blogs->name = $input['name'];
            $blogs->slug = str_slug($input['name']);
            $blogs->content = $input['content'];
            $blogs->publish_datetime = Carbon::parse($input['publish_datetime']);

            // Image Upload
            $image = $this->uploadImage($input);
            $blogs->featured_image = $image['featured_image'];

            $blogs->meta_title = $input['meta_title'];
            $blogs->cannonical_link = $input['cannonical_link'];
            $blogs->meta_keywords = $input['meta_keywords'];
            $blogs->meta_description = $input['meta_description'];
            $blogs->status = $input['status'];
            $blogs->created_by = access()->user()->id;

            if ($blogs->save()) {
                // Inserting associated category's id in mapper table
                if (count($categoriesArray)) {
                    $blogs->categories()->sync($categoriesArray);
                }

                // Inserting associated tag's id in mapper table
                if (count($tagsArray)) {
                    $blogs->tags()->sync($tagsArray);
                }

                event(new BlogCreated($blogs));

                return true;
            }

            throw new GeneralException(trans('exceptions.backend.blogs.create_error'));
        });
    }

    /**
     * Update Blog.
     *
     * @param $blogs
     * @param array $input
     */
    public function update($blogs, array $input)
    {
        $tagsArray = $this->createTagsArray($input['tags']);
        $categoriesArray = $this->createCategoriesArray($input['categories']);

        $blogs->name = $input['name'];
        $blogs->slug = str_slug($input['name']);
        $blogs->content = $input['content'];
        $blogs->publish_datetime = Carbon::parse($input['publish_datetime']);
        $blogs->meta_title = $input['meta_title'];
        $blogs->cannonical_link = $input['cannonical_link'];
        $blogs->meta_keywords = $input['meta_keywords'];
        $blogs->meta_description = $input['meta_description'];
        $blogs->status = $input['status'];
        $blogs->updated_by = access()->user()->id;

        // Uploading Image
        if (array_key_exists('featured_image', $input)) {
            $this->deleteOldFile($blogs);
            $input = $this->uploadImage($input);
            $blogs->featured_image = $input['featured_image'];
        }

        DB::transaction(function () use ($blogs, $input, $tagsArray, $categoriesArray) {
            if ($blogs->save()) {

                // Updateing associated category's id in mapper table
                if (count($categoriesArray)) {
                    $blogs->categories()->sync($categoriesArray);
                }

                // Updating associated tag's id in mapper table
                if (count($tagsArray)) {
                    $blogs->tags()->sync($tagsArray);
                }

                event(new BlogUpdated($blogs));

                return true;
            }

            throw new GeneralException(
                trans('exceptions.backend.blogs.update_error')
            );
        });
    }

    /**
     * Creating Tags Array.
     *
     * @param Array($tags)
     *
     * @return array
     */
    public function createTagsArray($tags)
    {
        //Creating a new array for tags (newly created)
        $tags_array = [];

        foreach ($tags as $tag) {
            if (is_numeric($tag)) {
                $tags_array[] = $tag;
            } else {
                $newTag = BlogTag::create(['name' => $tag, 'status' => 1, 'created_by' => 1]);
                $tags_array[] = $newTag->id;
            }
        }

        return $tags_array;
    }

    /**
     * Creating Tags Array.
     *
     * @param Array($tags)
     *
     * @return array
     */
    public function createCategoriesArray($categories)
    {
        //Creating a new array for categories (newly created)
        $categories_array = [];

        foreach ($categories as $category) {
            if (is_numeric($category)) {
                $categories_array[] = $category;
            } else {
                $newCategory = BlogCategory::create(['name' => $category, 'status' => 1, 'created_by' => 1]);

                $categories_array[] = $newCategory->id;
            }
        }

        return $categories_array;
    }

    /**
     * @param Model $blog
     *
     * @throws GeneralException
     *
     * @return bool
     */
    public function delete(Model $blog)
    {
        DB::transaction(function () use ($blog) {
            if ($blog->delete()) {
                BlogMapCategory::where('blog_id', $blog->id)->delete();
                BlogMapTag::where('blog_id', $blog->id)->delete();

                event(new BlogDeleted($blog));

                return true;
            }

            throw new GeneralException(trans('exceptions.backend.blogs.delete_error'));
        });
    }

    /**
     * Upload Image.
     *
     * @param array $input
     *
     * @return array $input
     */
    public function uploadImage($input)
    {
        $uploadManager = new FileUploads();
        $avatar = $input['featured_image'];

        if (isset($input['featured_image']) && !empty($input['featured_image'])) {
            $fileName = $uploadManager->setBasePath('backend/blog_images')
                ->setThumbnailFlag(false)
                ->upload($input['featured_image']);

            $input = array_merge($input, ['featured_image' => $fileName]);

            return $input;
        }
    }

    /**
     * Destroy Old Image.
     *
     * @param int $id
     */
    public function deleteOldFile($model)
    {
        $uploadManager = new FileUploads();
        $fileName = $model->featured_image;
        $filePath = $uploadManager->setBasePath('backend/blog_images');
        $file = $filePath->filePath.DIRECTORY_SEPARATOR.$fileName;

        return $uploadManager->deleteFile($file);
    }
}
