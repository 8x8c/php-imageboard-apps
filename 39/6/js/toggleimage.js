document.addEventListener("DOMContentLoaded", function() {
  // Create an overlay element for the lightbox
  var overlay = document.createElement("div");
  overlay.id = "lightbox-overlay";
  overlay.style.display = "none";
  overlay.style.position = "fixed";
  overlay.style.top = 0;
  overlay.style.left = 0;
  overlay.style.width = "100%";
  overlay.style.height = "100%";
  overlay.style.backgroundColor = "rgba(0,0,0,0.8)";
  overlay.style.zIndex = "1000";
  overlay.style.justifyContent = "center";
  overlay.style.alignItems = "center";
  overlay.style.cursor = "pointer";
  overlay.style.padding = "20px";
  overlay.style.boxSizing = "border-box";
  document.body.appendChild(overlay);

  // Create an image element for the lightbox
  var lightboxImg = document.createElement("img");
  lightboxImg.style.maxWidth = "90%";
  lightboxImg.style.maxHeight = "90%";
  lightboxImg.style.boxShadow = "0 0 15px rgba(255,255,255,0.8)";
  overlay.appendChild(lightboxImg);

  // When clicking on a thumbnail, show the overlay with the full image
  var thumbs = document.querySelectorAll(".thumb");
  thumbs.forEach(function(thumb) {
    thumb.addEventListener("click", function(e) {
      e.preventDefault();
      e.stopPropagation();
      var parentAnchor = thumb.closest("a");
      if (parentAnchor) {
        var fullImageSrc = parentAnchor.getAttribute("href");
        lightboxImg.src = fullImageSrc;
        overlay.style.display = "flex";
      }
    });
  });

  // Clicking anywhere on the overlay hides the lightbox
  overlay.addEventListener("click", function() {
    overlay.style.display = "none";
  });
});
