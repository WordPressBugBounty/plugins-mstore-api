<?php

class FlutterBlogHelper
{
    private function get_authenticated_user_id($request)
    {
        $raw_token = $request->get_param('token');
        if ($raw_token === null && isset($request['token'])) {
            $raw_token = $request['token'];
        }

        $token = sanitize_text_field($raw_token);
        if (empty($token)) {
            return new WP_Error("unauthorized", "You are not allowed to do this", array('status' => 401));
        }

        $cookie = urldecode(base64_decode($token));
        $user_id = validateCookieLogin($cookie);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        return (int) $user_id;
    }

    public function get_blog_from_dynamic_link($request)
    {
        if (isset($request['url'])) {
            $url = $request['url'];
            $post_id = url_to_postid($url);
            if ($post_id) {
                $post = get_post($post_id);
                $controller = new WP_REST_Posts_Controller('post');
                $req = new WP_REST_Request('GET');
                $params = array('id' => $post_id);
                $req->set_query_params($params);
                $response = $controller->prepare_item_for_response($post, $req);
                $data = $controller->prepare_response_for_collection($response);
                return $data;
            }
        }
        return new WP_Error("invalid_url", "Not Found", array('status' => 404));
    }

    public function create_blog($request){
        $title = isset($request['title']) ? sanitize_text_field($request['title']) : '';
        $author = isset($request['author']) ? intval($request['author']) : 0;
        $status = isset($request['status']) ? sanitize_text_field($request['status']) : 'draft';
        $categories = isset($request['categories']) ? sanitize_text_field($request['categories']) : '';
        $image = isset($request['image']) ? sanitize_text_field($request['image']) : '';

        $content = '';
        if (isset($request['content'])) {
            if (!is_scalar($request['content'])) {
                return new WP_Error("invalid_content", "Content must be a string.", array('status' => 400));
            }
            $content = wp_kses_post(wp_unslash((string) $request['content']));
        }

        if ($author <= 0) {
            return new WP_Error("invalid_author", "Invalid author", array('status' => 400));
        }

        $user_id = $this->get_authenticated_user_id($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        if ((int) $user_id !== (int) $author) {
            return new WP_Error("unauthorized", "You are not allowed to do this", array('status' => 401));
		}

        wp_set_current_user( $user_id );
        if ( !current_user_can( 'edit_posts' ) ) {
            return new WP_Error("unauthorized", "You are not allowed to create this post", array('status' => 401));
        }

        // Validate and set post status
        $allowed_statuses = array('publish', 'draft', 'pending', 'private', 'future');
        if ($status == 'publish' || $status == 'published') {
            if ( !current_user_can( 'publish_posts' ) ) {
                return new WP_Error("unauthorized", "You are not allowed to publish this post", array('status' => 401));
            }
            $status = 'publish';
        } elseif (!in_array($status, $allowed_statuses)) {
            // If status is not in allowed list, default to draft
            $status = 'draft';
        }

        $my_post = array(
            'post_author' => $user_id,
            'post_title'   => $title,
            'post_content' => $content,
            'post_status' => $status,
        );

        $post_id = wp_insert_post( $my_post );

		if (!is_wp_error($post_id) && !empty($categories)) {
            wp_set_post_categories($post_id, array(intval($categories)), false);
        }

		if (!empty($image)) {
            $img_id = upload_image_from_mobile($image, 0 ,$user_id);
            if($img_id != false){
                set_post_thumbnail($post_id, $img_id);
            }
		}

        return new WP_REST_Response(
            [
                "status" => "success",
                "response" => '',
            ],
            200
        );
	}

    public function update_blog($request){
        $post_id = isset($request['id']) ? intval($request['id']) : 0;
        if ($post_id <= 0) {
            return new WP_Error("invalid_request", "Invalid post id", array('status' => 400));
        }
        $title = isset($request['title']) ? sanitize_text_field($request['title']) : null;
        $content = null;
        if (isset($request['content'])) {
            if (!is_scalar($request['content'])) {
                return new WP_Error("invalid_content", "Content must be a string.", array('status' => 400));
            }
            $content = wp_kses_post(wp_unslash((string) $request['content']));
        }
        $status = isset($request['status']) ? sanitize_text_field($request['status']) : null;
        $categories = isset($request['categories']) ? sanitize_text_field($request['categories']) : null;
        $image = isset($request['image']) ? sanitize_text_field($request['image']) : '';

        $user_id = $this->get_authenticated_user_id($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $post = get_post($post_id);
        if (empty($post) || $post->post_type !== 'post') {
            return new WP_Error("invalid_post", "Post not found", array('status' => 404));
        }

        wp_set_current_user($user_id);
        if ((int) $post->post_author !== (int) $user_id || !current_user_can('edit_post', $post_id)) {
            return new WP_Error("unauthorized", "You are not allowed to edit this post", array('status' => 401));
        }

        $allowed_statuses = array('publish', 'draft', 'pending', 'private', 'future');
        if ($status === 'publish' || $status === 'published') {
            if (!current_user_can('publish_posts')) {
                return new WP_Error("unauthorized", "You are not allowed to publish this post", array('status' => 401));
            }
            $status = 'publish';
        } elseif (!empty($status) && !in_array($status, $allowed_statuses)) {
            $status = 'draft';
        }

        $my_post = array(
            'ID' => $post_id,
        );

        if ($title !== null) {
            $my_post['post_title'] = $title;
        }

        if ($content !== null) {
            $my_post['post_content'] = $content;
        }

        if (!empty($status)) {
            $my_post['post_status'] = $status;
        }

        $updated_post_id = wp_update_post($my_post, true);
        if (is_wp_error($updated_post_id)) {
            return $updated_post_id;
        }

        if ($categories !== null) {
            $category_ids = empty($categories) ? array() : array(intval($categories));
            wp_set_post_categories($post_id, $category_ids, false);
        }

        if (!empty($image)) {
            $img_id = upload_image_from_mobile($image, 0, $user_id);
            if ($img_id != false) {
                set_post_thumbnail($post_id, $img_id);
            }
        }

        return new WP_REST_Response(
            [
                "status" => "success",
                "response" => '',
            ],
            200
        );
    }

    public function delete_blog($request){
        $post_id = intval($request->get_param('id'));
        if ($post_id <= 0) {
            return new WP_Error("invalid_request", "Invalid post id", array('status' => 400));
        }
        $user_id = $this->get_authenticated_user_id($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $post = get_post($post_id);
        if (empty($post) || $post->post_type !== 'post') {
            return new WP_Error("invalid_post", "Post not found", array('status' => 404));
        }

        wp_set_current_user($user_id);
        if ((int) $post->post_author !== (int) $user_id || !current_user_can('delete_post', $post_id)) {
            return new WP_Error("unauthorized", "You are not allowed to delete this post", array('status' => 401));
        }

        $deleted = wp_delete_post($post_id, false);
        if ($deleted === false || is_wp_error($deleted)) {
            return new WP_Error("delete_failed", "Could not delete post", array('status' => 400));
        }

        return new WP_REST_Response(
            [
                "status" => "success",
                "response" => '',
            ],
            200
        );
    }

    public function create_comment($request){
		$content = sanitize_text_field($request['content']);
		$post_id = sanitize_text_field($request['post_id']);

        $user_id = $this->get_authenticated_user_id($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

		$is_approved = get_option( 'comment_moderation' ) ;
	    if ( comments_open( $post_id ) ) {
			$current_user = get_user_by('ID',$user_id);
                $data = array(
                'comment_post_ID'      => $post_id,
                'comment_content'      => $content,
                'user_id'              => $current_user->ID,
                'comment_author'       => $current_user->user_login,
                'comment_author_email' => $current_user->user_email,
                'comment_author_url'   => $current_user->user_url,
                'comment_approved'	   => empty($is_approved) ? 1 : 0,
            );

            $comment_id = wp_insert_comment( $data );
            if ( ! is_wp_error( $comment_id ) ) {
                return true;
            }else{
                return new WP_Error("error", $comment_id, array('status' => 400));
            }
        }else{
            return new WP_Error("comments_open", "This post doesn't allow to  comment", array('status' => 400));
        }
	}

	public function get_user_posts($request){
		$author = sanitize_text_field($request['author']);

        $user_id = $this->get_authenticated_user_id($request);
        if (is_wp_error($user_id)) {
            return $user_id;
        }

		// Verify user is requesting their own posts
        if ((int)$user_id !== (int)$author) {
            return new WP_Error("unauthorized", "You are not allowed to do this", array('status' => 401));
		}

        // Get posts with all statuses for authenticated user
        // Pagination parameters
        $page = isset($request['page']) ? max(1, intval($request['page'])) : 1;
        $per_page = isset($request['per_page']) ? intval($request['per_page']) : 20;
        $per_page = max(1, min($per_page, 50)); // Limit per_page between 1 and 50
        $args = array(
            'author' => $author,
            'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        $posts = get_posts($args);

        if (empty($posts)) {
            return array();
        }

        $controller = new WP_REST_Posts_Controller('post');
        $data = array();

        foreach ($posts as $post) {
            $req = new WP_REST_Request('GET');
            $params = array('id' => $post->ID);
            $req->set_query_params($params);
            $response = $controller->prepare_item_for_response($post, $req);
            $data[] = $controller->prepare_response_for_collection($response);
        }

        return $data;
	}
}
?>
