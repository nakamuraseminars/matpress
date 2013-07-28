// when document is ready
//function myFunction() {
//  alert("Hello My Cruel World!");
//}
jQuery(document).ready(
  function($) {
    var lastform = undefined;
    function submit_me(id) {
      var mpid = '#' + id; 
      var thisform = $( mpid + '-__form' ).serialize(); 
      //alert('mpid:  ' + mpid + '\nform:  ' + thisform + '\nurl:   ' + MyAjax.ajaxurl + '\nnonce: ' + MyAjax.nonce);
      if( lastform != thisform ) {
	$( mpid + '-__status' ).html('Please wait...');
        $.ajax( 
          { 
	    type:	'POST', 
	    url:	MyAjax.ajaxurl, 
	    data:	"action=myajax-submit&nonce=" + MyAjax.nonce + "&" + thisform,
	    success:	function(response) 
	      { 
                //alert('success: ' + response);
  		$( ".nsmpoutput[id^='" + id + "']" ).each(function(){
    		  $(this).html('');
  	      	});
  		var obj = JSON.parse(response); 
  		$.each(obj, function(index, value) {
    		  var element = mpid + '-' + index;  
    		  //if( $(element).length > 0 ) $(element).html( index + ' : ' + obj[index] );
    		  if( $(element).length > 0 ) $(element).html( obj[index] );
  		});
	      }
          }
        );
        lastform = thisform;
      }

    }
    $('.nsmpsubmit').click(
      function() { // pick out id from id or name next
        id = $(this).attr('id');
        //alert('id: ' + id);
        submit_me(id); 
        //$(this).parent().preventDefault();
        //return false;
      }
    );
  } 
);