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

  if (!(sidebar instanceof HTMLElement)) {
    return;
  }

  const setCollapsed = (collapsed) => {
    const state = collapsed ? 'collapsed' : 'expanded';
    body.dataset.sidebarState = state;
    setPreferenceCookie('formvex_sidebar', state);
    if (toggleButton instanceof HTMLButtonElement) {
      toggleButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
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

if (typeof document !== 'undefined') {
  document.addEventListener('DOMContentLoaded', () => {
    initialiseTheme();
    initialiseSidebar();
  });
}

export const spokeAdminAsset = 'formvex/spoke-admin';
