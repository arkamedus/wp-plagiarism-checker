var noDuplicates = false;
jQuery(document).ready(function() {
  jQuery('#publish').click(function(e){
    var message = 'Editor or Administrator Role required to Publish because Duplicate Content was found or not checked.\n\n Post will be saved as Pending Review Status';
    if (!noDuplicates) alert(message);
   	jQuery('#publish').unbind('click', this);
  });
});  
