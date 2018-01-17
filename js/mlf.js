jQuery(function ($) {
    $("#mlf_taxonomy_dd").on('change', function (e) {
        $("#mlf_term_dd").remove();
        e.preventDefault();
    });
});