import Swal from "sweetalert2";

const calendarPath = "/calendar";
let activeCalendarLoadingId = 0;

const showCalendarLoading = () => {
    const loadingId = ++activeCalendarLoadingId;

    Swal.fire({
        toast: true,
        position: "top",
        title: "Actualizando calendario…",
        showConfirmButton: false,
        didOpen: (popup) => {
            popup.dataset.calendarLoadingId = String(loadingId);
            Swal.showLoading();
        },
    });

    return loadingId;
};

const hideCalendarLoading = (loadingId) => {
    if (activeCalendarLoadingId !== loadingId) {
        return;
    }

    if (Swal.getPopup()?.dataset.calendarLoadingId === String(loadingId)) {
        Swal.close();
    }
};

const isCalendarUrl = (url) => url.origin === window.location.origin && url.pathname === calendarPath;

const replaceCalendarPage = (documentHtml) => {
    const nextDocument = new DOMParser().parseFromString(documentHtml, "text/html");
    const currentPage = document.querySelector("[data-calendar-page]");
    const nextPage = nextDocument.querySelector("[data-calendar-page]");

    if (!currentPage || !nextPage) {
        return false;
    }

    currentPage.replaceWith(nextPage);
    document.title = nextDocument.title;
    nextPage.querySelector("[data-testid='calendar-period']")?.focus({ preventScroll: true });

    return true;
};

const visitCalendar = async (url, { push = true } = {}) => {
    const page = document.querySelector("[data-calendar-page]");

    if (!page || !isCalendarUrl(url)) {
        window.location.assign(url);
        return;
    }

    page.setAttribute("aria-busy", "true");
    const loadingId = showCalendarLoading();

    try {
        const response = await fetch(url, {
            headers: { "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        });

        if (!response.ok || !replaceCalendarPage(await response.text())) {
            window.location.assign(url);
            return;
        }

        if (push) {
            window.history.pushState({ calendar: true }, "", url);
        }
    } catch {
        window.location.assign(url);
    } finally {
        hideCalendarLoading(loadingId);
        document.querySelector("[data-calendar-page]")?.removeAttribute("aria-busy");
    }
};

document.addEventListener("click", (event) => {
    const link = event.target.closest("a[data-calendar-navigation]");

    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    const url = new URL(link.href, window.location.href);
    if (!isCalendarUrl(url)) {
        return;
    }

    event.preventDefault();
    visitCalendar(url);
});

document.addEventListener("submit", (event) => {
    const form = event.target.closest("form[data-calendar-navigation-form]");
    if (!form) {
        return;
    }

    const url = new URL(form.action, window.location.href);
    url.search = new URLSearchParams(new FormData(form)).toString();

    event.preventDefault();
    visitCalendar(url);
});

document.addEventListener("change", (event) => {
    const picker = event.target.closest("input[data-calendar-date-picker]");
    if (!picker || !picker.validity.valid || !picker.value) {
        return;
    }

    picker.form?.requestSubmit();
});

window.addEventListener("popstate", () => {
    if (isCalendarUrl(new URL(window.location.href))) {
        visitCalendar(new URL(window.location.href), { push: false });
    }
});
