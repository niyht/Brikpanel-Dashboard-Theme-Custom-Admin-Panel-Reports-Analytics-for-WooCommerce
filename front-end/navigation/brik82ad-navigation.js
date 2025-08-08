const brik82adNavigation = document.querySelector("#brik82ad-navigation");
const container = document.querySelector("#adminmenuwrap");
container.insertAdjacentElement("afterbegin", brik82adNavigation);

const chevrons = document.querySelectorAll(".brik82ad-menu-chevron");
for (const chevron of chevrons) {
    chevron.addEventListener("click", event => {
        event.preventDefault();
        const li = event.target.parentNode.parentNode;
        li.classList.toggle("brik82ad-has-open-submenu");
        const submenu = li.querySelector(".brik82ad-submenu");
        submenu.classList.toggle("visible");
    });
}
