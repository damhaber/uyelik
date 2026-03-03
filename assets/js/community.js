(function($){
    $(function(){

        // Tab switch
        $('.ai-community-tab-btn').on('click', function(){
            var tab = $(this).data('tab');

            $('.ai-community-tab-btn').removeClass('active');
            $(this).addClass('active');

            $('.ai-community-tab-panel').removeClass('active');
            $('.ai-community-tab-panel[data-tab="'+tab+'"]').addClass('active');
        });

    });
})(jQuery);
