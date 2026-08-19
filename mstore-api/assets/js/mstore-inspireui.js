jQuery(document).ready(function ($) {
  // ==========================================================================
  // JSON file management
  // ==========================================================================
  $(document).on("click", ".mstore-delete-json-file", function () {
    var id = $(this).data("id");
    var nonce = $(this).data("nonce");
    $.ajax({
      type: "post",
      url: MyAjax.ajaxurl,
      data: {
        action: "mstore_delete_json_file",
        id: id,
        nonce: nonce,
      },
      success: function (result) {
        if (result == "success") {
          location.reload();
        }
      },
    });
    return false;
  });

  // ==========================================================================
  // Apple Sign-In file management
  // ==========================================================================
  $(document).on("change", "input[name='appleFileToUpload']", function () {
    $("button[name='but_apple_sign_in_submit']").click();
  });

  $(document).on("click", ".mstore-delete-apple-file", function () {
    var nonce = $(this).data("nonce");
    $.ajax({
      type: "post",
      url: MyAjax.ajaxurl,
      data: {
        action: "mstore_delete_apple_file",
        nonce: nonce,
      },
      success: function (result) {
        if (result == "success") {
          location.reload();
        }
      },
    });
    return false;
  });

  // ==========================================================================
  // Firebase file management
  // ==========================================================================
  $(document).on("change", "input[name='firebaseFileToUpload']", function () {
    $("button[name='but_firebase_submit']").click();
  });

  $(document).on("click", ".mstore-delete-firebase-file", function () {
    var nonce = $(this).data("nonce");
    $.ajax({
      type: "post",
      url: MyAjax.ajaxurl,
      data: {
        action: "mstore_delete_firebase_file",
        nonce: nonce,
      },
      success: function (result) {
        if (result == "success") {
          location.reload();
        }
      },
    });
    return false;
  });

  // ==========================================================================
  // Category image management
  // ==========================================================================
  if ($(".flutter_category_media_button").length > 0) {
    if (typeof wp !== "undefined" && wp.media && wp.media.editor) {
      $(document).on(
        "click",
        ".flutter_category_media_button",
        function (e) {
          e.preventDefault();
          var button = $(this);
          var imageIdInput = $("#category-image-id");
          var imageWrapper = $("#category-image-wrapper");

          var custom_uploader = wp
            .media({
              title: "Select Image",
              button: {
                text: "Use this image",
              },
              multiple: false,
            })
            .on("select", function () {
              var attachment = custom_uploader
                .state()
                .get("selection")
                .first()
                .toJSON();
              imageIdInput.val(attachment.id);
              imageWrapper.html(
                '<img src="' +
                  attachment.url +
                  '" style="max-width:150px;height:auto;" />'
              );
            })
            .open();
        }
      );

      $(document).on("click", ".flutter_category_media_remove", function (e) {
        e.preventDefault();
        $("#category-image-id").val("");
        $("#category-image-wrapper").html("");
      });
    }
  }
});
