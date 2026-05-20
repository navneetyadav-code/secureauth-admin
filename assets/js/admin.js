(function () {
  "use strict";

  const sidebar = document.querySelector(".admin-sidebar");
  const sidebarToggle = document.querySelector("[data-toggle='sidebar']");
  const navLinks = document.querySelectorAll(".admin-sidebar a");

  if (!sidebar || !sidebarToggle) {
    return;
  }

  function closeSidebar() {
    sidebar.classList.remove("open");
  }

  sidebarToggle.addEventListener("click", function (event) {
    event.stopPropagation();
    sidebar.classList.toggle("open");
  });

  document.addEventListener("click", function (event) {
    if (sidebar.classList.contains("open") && !sidebar.contains(event.target)) {
      closeSidebar();
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      closeSidebar();
    }
  });

  navLinks.forEach(function (link) {
    link.addEventListener("click", closeSidebar);
  });
})();
