import '@formvex/platform-ui';

const validThemes = new Set(['light', 'dark']);
const validSidebarStates = new Set(['expanded', 'collapsed']);

const cookieValue = (name) => {
  const encodedName = `${name}=`;
  const cookie = document.cookie.split('; ').find((item) => item.startsWith(encodedName));

  return cookie ? cookie.slice(encodedName.length) : null;
};

const setPreferenceCookie = (name, value) => {
  document.cookie = `${name}=${value}; Path=/formvex; SameSite=Lax; Secure`;
};

const applyTheme = (theme, persist = false) => {
  if (!validThemes.has(theme)) {
    return;
  }

  document.documentElement.dataset.theme = theme;
  document.documentElement.style.colorScheme = theme;
  if (persist) {
    setPreferenceCookie('formvex_theme', theme);
  }

  document.querySelectorAll('[data-theme-label]').forEach((label) => {
    label.textContent = theme.charAt(0).toUpperCase() + theme.slice(1);
  });

  document.querySelectorAll('[data-theme-toggle]').forEach((control) => {
    if ('value' in control) {
      control.value = theme === 'dark' ? 'light' : 'dark';
    }
  });
};

const initialiseTheme = () => {
  const savedTheme = cookieValue('formvex_theme');
  const preferredTheme = validThemes.has(savedTheme)
    ? savedTheme
    : window.matchMedia('(prefers-color-scheme: dark)').matches
      ? 'dark'
      : 'light';

  applyTheme(preferredTheme);

  document.querySelectorAll('[data-theme-toggle]').forEach((control) => {
    control.addEventListener('click', (event) => {
      if (control.form) {
        return;
      }

      event.preventDefault();
      const currentTheme = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
      applyTheme(currentTheme === 'dark' ? 'light' : 'dark', true);
    });
  });

  document.querySelectorAll('[data-theme-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const submitter = event.submitter;
      const theme = submitter instanceof HTMLButtonElement ? submitter.value : null;

      if (theme === 'light' || theme === 'dark') {
        applyTheme(theme, true);
        void fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { Accept: 'text/html' },
        });
      }
    });
  });
};

const initialiseSidebar = () => {
  const body = document.body;
  const sidebar = document.querySelector('[data-sidebar]');
  const backdrop = document.querySelector('[data-sidebar-backdrop]');
  const openButton = document.querySelector('[data-sidebar-open]');
  const closeButton = document.querySelector('[data-sidebar-close]');
  const toggleButton = document.querySelector('[data-sidebar-toggle]');
  const toggleIcon = toggleButton?.querySelector('[data-sidebar-collapse-icon]');
  const toggleLabel = toggleButton?.querySelector('[data-sidebar-collapse-label]');

  if (!(sidebar instanceof HTMLElement)) {
    return;
  }

  const setCollapsed = (collapsed) => {
    const state = collapsed ? 'collapsed' : 'expanded';
    body.dataset.sidebarState = state;
    setPreferenceCookie('formvex_sidebar', state);
    if (toggleButton instanceof HTMLButtonElement) {
      toggleButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      toggleButton.setAttribute(
        'aria-label',
        collapsed ? 'Expand navigation' : 'Collapse navigation',
      );
    }
    if (toggleIcon instanceof HTMLElement) {
      toggleIcon.textContent = collapsed ? '›' : '‹';
    }
    if (toggleLabel instanceof HTMLElement) {
      toggleLabel.textContent = collapsed ? 'Expand navigation' : 'Collapse navigation';
    }
  };

  const setDrawerOpen = (open) => {
    body.classList.toggle('fv-sidebar-open', open);
    if (backdrop instanceof HTMLElement) {
      backdrop.hidden = !open;
    }
    if (openButton instanceof HTMLButtonElement) {
      openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (open) {
      closeButton?.focus();
    } else {
      openButton?.focus();
    }
  };

  const savedState = cookieValue('formvex_sidebar');
  if (validSidebarStates.has(savedState)) {
    setCollapsed(savedState === 'collapsed');
  }

  toggleButton?.addEventListener('click', () => {
    setCollapsed(body.dataset.sidebarState !== 'collapsed');
  });
  openButton?.addEventListener('click', () => setDrawerOpen(true));
  closeButton?.addEventListener('click', () => setDrawerOpen(false));
  backdrop?.addEventListener('click', () => setDrawerOpen(false));
  sidebar
    .querySelectorAll('a')
    .forEach((link) => link.addEventListener('click', () => setDrawerOpen(false)));
  document.addEventListener('keydown', (event) => {
    if (!body.classList.contains('fv-sidebar-open')) {
      return;
    }

    if (event.key === 'Escape') {
      setDrawerOpen(false);
      return;
    }

    if (event.key !== 'Tab') {
      return;
    }

    const focusable = [...sidebar.querySelectorAll('a[href], button:not([disabled])')];
    const first = focusable.at(0);
    const last = focusable.at(-1);

    if (!(first instanceof HTMLElement) || !(last instanceof HTMLElement)) {
      return;
    }

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
};

const initialiseSmtpPortDefaults = () => {
  const encryption = document.querySelector('#smtp-encryption');
  const port = document.querySelector('#smtp-port');

  if (!(encryption instanceof HTMLSelectElement) || !(port instanceof HTMLInputElement)) {
    return;
  }

  encryption.addEventListener('change', () => {
    port.value = encryption.value === 'starttls' ? '587' : '465';
  });
};

const initialisePaginationControls = () => {
  document.querySelectorAll('[data-pagination-page-size]').forEach((control) => {
    if (!(control instanceof HTMLSelectElement) || !control.form) {
      return;
    }

    control.addEventListener('change', () => {
      if (typeof control.form.requestSubmit === 'function') {
        control.form.requestSubmit();
      } else {
        control.form.submit();
      }
    });
  });
};

const initialiseDiscoveryDialogs = () => {
  document.documentElement.classList.add('fv-js');

  const dialogs = [...document.querySelectorAll('[data-discovery-dialog]')];

  if (dialogs.length === 0) {
    return;
  }

  let activeDialog = null;
  let activeOpener = null;

  const closeDialog = (dialog) => {
    dialog.classList.remove('is-open');
    dialog.setAttribute('aria-hidden', 'true');

    if (activeDialog === dialog) {
      activeDialog = null;
      activeOpener?.focus();
      activeOpener = null;
    }
  };

  dialogs.forEach((dialog) => {
    dialog.setAttribute('aria-hidden', 'true');
    dialog.querySelectorAll('[data-modal-close]').forEach((control) => {
      control.addEventListener('click', () => closeDialog(dialog));
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) {
        closeDialog(dialog);
      }
    });
  });

  document.querySelectorAll('[data-discovery-dialog-open]').forEach((control) => {
    const targetId = control.getAttribute('data-discovery-dialog-open');
    const dialog = targetId ? document.getElementById(targetId) : null;

    if (!(control instanceof HTMLButtonElement) || !(dialog instanceof HTMLElement)) {
      return;
    }

    control.addEventListener('click', () => {
      if (activeDialog instanceof HTMLElement) {
        closeDialog(activeDialog);
      }

      activeDialog = dialog;
      activeOpener = control;
      dialog.classList.add('is-open');
      dialog.setAttribute('aria-hidden', 'false');
      (dialog.querySelector('input, select, textarea') ?? dialog.querySelector('button'))?.focus();
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && activeDialog instanceof HTMLElement) {
      closeDialog(activeDialog);
    }
  });
};

if (typeof document !== 'undefined') {
  document.addEventListener('DOMContentLoaded', () => {
    initialiseTheme();
    initialiseSidebar();
    initialiseSmtpPortDefaults();
    initialisePaginationControls();
    initialiseDiscoveryDialogs();
  });
}

export const spokeAdminAsset = 'formvex/spoke-admin';
