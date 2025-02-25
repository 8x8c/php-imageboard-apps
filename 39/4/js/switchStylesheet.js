document.addEventListener('DOMContentLoaded', function() {
  var select = document.getElementById('switchStylesheet');
  var stylesheet = document.getElementById('stylesheet');
  select.addEventListener('change', function() {
    var newSheet = this.value;
    if (newSheet) {
      stylesheet.setAttribute('href', newSheet);
    }
  });
});
