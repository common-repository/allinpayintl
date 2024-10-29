jQuery(document).ready(function ($) {
  $('#allinpay_query_btn').click(function () {
    var order_id = $(this).data('order-id');
    var nonce = custom_script_vars.nonce;
	var ajaxurl = custom_script_vars.ajaxurl; 
   //var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
	console.log(ajaxurl);

// 将提示信息 div 添加到页面中（可根据需要放置在合适的位置）
// document.body.appendChild(messageDiv);
//
// // 设定一定时间后隐藏提示信息
// setTimeout(function() {
//     messageDiv.style.display = 'none';
//     }, 3000); 
    $.ajax({
      url: ajaxurl,
      type: 'POST',
	dataType: 'json',
      data: {
        action: 'query_order',
        order_id: order_id,
	nonce: nonce
      },
      success: function (resp) {
	console.log(resp);
        if (resp.success) {
	  if(resp.data==='handler'){
           var noticeDiv = $('<div class="notice notice-success is-dismissible"><p>' + 'Order processing, please wait for a moment to check.' + '</p></div>');
           $('.wrap').prepend(noticeDiv);
	 setTimeout(function() {
        $('.notice-success').fadeOut(500, function() {
            $(this).remove();
        });
    }, 3000); 
	}else{
	   location.reload();
	}
          // Handle successful query response
           console.log('Query Result:', resp.data);
        } else {
          // Handle error response
          console.log('Error:', resp.data);
	alert(resp.status);
        }
      },
      error: function (xhr, status, error) {
        // Handle AJAX error
        console.log('AJAX Error:', error);
      },
    });
  });
});
