(function () {
  "use strict";

  document.querySelectorAll("[data-urbizen-form-frame]").forEach(function (frame) {
    frame.addEventListener("load", function () {
      var doc;

      try {
        doc = frame.contentDocument;
      } catch (e) {
        return;
      }

      if (!doc || !doc.documentElement) return;

      var resize = function () {
        var bodyHeight = doc.body ? doc.body.scrollHeight : 0;
        var rootHeight = doc.documentElement.scrollHeight || 0;
        frame.style.height = Math.max(bodyHeight, rootHeight, 900) + "px";
      };

      resize();

      if ("ResizeObserver" in window && doc.body) {
        var observer = new ResizeObserver(resize);
        observer.observe(doc.body);
      }

      window.addEventListener("resize", resize);
    });
  });
})();
