// Mobile drawer + bottom-sheet interactions for the DFMS layout shell.
document.addEventListener('DOMContentLoaded', function () {
    var menuButton = document.querySelector('[data-drawer-open]');
    var closeButtons = document.querySelectorAll('[data-drawer-close]');
    var drawer = document.querySelector('.dfms-drawer');
    var backdrop = document.querySelector('.dfms-drawer-backdrop');

    function openDrawer() {
        if (drawer) drawer.classList.add('open');
        if (backdrop) backdrop.classList.add('open');
    }

    function closeDrawer() {
        if (drawer) drawer.classList.remove('open');
        if (backdrop) backdrop.classList.remove('open');
    }

    if (menuButton) menuButton.addEventListener('click', openDrawer);
    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeDrawer);
    });
});
