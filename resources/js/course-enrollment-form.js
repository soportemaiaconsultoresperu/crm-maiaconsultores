const updateCourseEnrollmentSource = (form) => {
    const selected = form.querySelector(
        "[data-course-enrollment-source]:checked",
    );
    const selectedSource = selected?.value;
    const summary = form.querySelector(
        "[data-course-enrollment-source-summary]",
    );

    form.querySelectorAll("[data-course-enrollment-source-panel]").forEach(
        (panel) => {
            const isSelected =
                panel.dataset.courseEnrollmentSourcePanel === selectedSource;
            panel.hidden = !isSelected;
        },
    );

    if (summary && selected?.dataset.sourceDescription) {
        summary.textContent = selected.dataset.sourceDescription;
    }
};

const preventDuplicateSubmit = (form) => {
    if (form.dataset.submitted === "true") {
        return false;
    }

    form.dataset.submitted = "true";

    window.requestAnimationFrame(() => {
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
        });
    });

    return true;
};

const initializeCourseEnrollmentForm = (form) => {
    updateCourseEnrollmentSource(form);

    form.querySelectorAll("[data-course-enrollment-source]").forEach(
        (radio) => {
            radio.addEventListener("change", () =>
                updateCourseEnrollmentSource(form),
            );
        },
    );

    form.addEventListener("submit", (event) => {
        if (!preventDuplicateSubmit(form)) {
            event.preventDefault();
        }
    });
};

document.addEventListener("DOMContentLoaded", () => {
    document
        .querySelectorAll("[data-course-enrollment-form]")
        .forEach(initializeCourseEnrollmentForm);
});
