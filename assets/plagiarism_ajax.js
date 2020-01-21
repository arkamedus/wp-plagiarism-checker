jQuery(document).ready(function() {

  jQuery('#plagiarism_loader').hide();
  jQuery('#plagiarism_loader').ajaxStart(function() {
    jQuery(this).show();
  });
  jQuery('#plagiarism_loader').ajaxStop(function() {
    jQuery(this).hide();
  });

  jQuery('#plagiarism_check_button').click(function(){
    jQuery.post( ajaxurl,
    {
      action: 'plagiarism_render_meta_box',
      timeout: 10000,
      search: jQuery('#plagiarism_search').val(),
      post_id: plagiarism_ajax.post,
      _ajax_nonce: plagiarism_ajax.nonce,
      plagiarism_ajax_call: 'true',
      dataType: 'html'
    },
    function(response,textStatus){
      if(textStatus == 'success'){
        jQuery('#plagiarism_meta_wrapper').html(response);
      } else {
        jQuery('#plagiarism_meta_wrapper').html('Status' + textStatus);
      }
    });
  });

  jQuery('#plagiarism_clear_button').click(function(){
    jQuery.post( ajaxurl,
    {
      action: 'plagiarism_clear_results',
      post_id: plagiarism_ajax.post,
      _ajax_nonce: plagiarism_ajax.nonce,
      plagiarism_ajax_call: 'true',
      dataType: 'html'
    },
    function(response){
      jQuery('#plagiarism_meta_wrapper').html(response);
    });
  });

});

