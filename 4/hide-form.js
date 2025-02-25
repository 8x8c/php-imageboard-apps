$(document).ready(function() {
    // Ensure active_page is defined and correct
    if (typeof active_page === 'undefined' || (active_page !== 'index' && active_page !== 'thread')) {
        return;
    }

    let form_el = $('form[name="post"]');
    if (form_el.length === 0) {
        console.warn("No form with name='post' found.");
        return;
    }

    // Determine the message
    let form_msg = (active_page === 'index') ? 'Start a New Thread' : 'Post a Reply';

    // Hide the form initially
    form_el.hide();

    // Add toggle link after the form
    form_el.after(
        '<div id="show-post-form" style="font-size:175%;text-align:center;font-weight:bold">' +
        '[<a href="#" style="text-decoration:none">' + form_msg + '</a>]' +
        '</div>'
    );

    // On click of the toggle link, show the form and hide the link
    $('#show-post-form').on('click', function(e) {
        e.preventDefault(); // Prevent link from navigating
        $(this).hide();
        form_el.show();
    });
});
