jQuery(document).ready(function(){
  
    var jq = jQuery;

    function migrate() {

        jq.post(ajaxurl, {action: 'mpp_migrate_bp_gallery'}, function (ret) {

            jq('#mpp-bp-gallery-migration-log').append(ret.message + '<br />');
            if (ret.remaining)
                migrate();

        }, 'json');
    }

    //Start Trigger
    jq('#mpp-bp-gallery-start-migration').click(function () {
        migrate();
        return false;
    });

});