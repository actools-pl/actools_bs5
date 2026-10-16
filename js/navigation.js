/**
 * @file
 * Progressive, native-list navigation for header menu block instances.
 */

((Drupal, once) => {
  const controllers = new WeakMap();
  const selector = '[data-actools-navigation][data-actools-nav-region="header"]';
  const desktopQuery = '(min-width: 64rem)';
  const navigationsIn = (context) => {
    const elements = [...context.querySelectorAll(selector)];
    if (context.matches?.(selector)) elements.unshift(context);
    return elements;
  };

  const initialize = (navigation) => {
    const header = navigation.closest('header[role="banner"]');
    const toggle = navigation.querySelector('[data-actools-nav-toggle]');
    const panel = navigation.querySelector('[data-actools-nav-panel]');
    if (!header || !toggle || !panel) return;

    const row = navigation.closest('.actools-site-header__row') || header;
    const abort = new AbortController();
    const { signal } = abort;
    const media = window.matchMedia(desktopQuery);
    const branches = new Map();
    navigation.querySelectorAll('[data-actools-submenu-toggle]').forEach((button) => {
      const target = document.getElementById(button.getAttribute('aria-controls'));
      if (target && navigation.contains(target)) branches.set(button, target);
    });
    let desktop;
    let firstMode = true;

    const notify = () => navigation.dispatchEvent(new Event('actools:navigation-change', { bubbles: true }));
    const branchOwner = (target) => [...branches].find(([, submenu]) => submenu === target)?.[0];
    const focusOwner = () => {
      const focused = document.activeElement;
      // Pick the outermost owner so focusing it cannot leave focus inside a
      // different submenu that close-all is about to hide.
      return [...branches].find(([, submenu]) => submenu.contains(focused))?.[0];
    };
    const setBranch = (button, open) => {
      const submenu = branches.get(button);
      if (!submenu) return;
      button.setAttribute('aria-expanded', String(open));
      submenu.hidden = !open;
      if (!open) {
        branches.forEach((descendant, childButton) => {
          if (submenu.contains(childButton)) {
            childButton.setAttribute('aria-expanded', 'false');
            descendant.hidden = true;
          }
        });
      }
    };
    const closeBranches = () => branches.forEach((_, button) => setBranch(button, false));
    const updateMeasurements = () => {
      const width = desktop ? 0 : Math.ceil(toggle.getBoundingClientRect().width);
      const value = `${width}px`;
      if (row.style.getPropertyValue('--actools-nav-toggle-width') !== value) {
        row.style.setProperty('--actools-nav-toggle-width', value);
      }
      if (!desktop && !panel.hidden) {
        // Panel top, unlike the expanded header's full height, does not depend
        // on panel height. This avoids a ResizeObserver sizing feedback loop.
        const available = Math.max(0, window.innerHeight - Math.max(0, panel.getBoundingClientRect().top) - 12);
        panel.style.setProperty('--actools-nav-panel-max-height', `${Math.floor(available)}px`);
      }
      else panel.style.removeProperty('--actools-nav-panel-max-height');
      const topList = panel.querySelector('.actools-navigation__list');
      branches.forEach((submenu, button) => {
        submenu.style.removeProperty('--actools-submenu-shift');
        if (desktop && !submenu.hidden && button.closest('li')?.parentElement === topList) {
          const rect = submenu.getBoundingClientRect();
          let shift = Math.max(0, 12 - rect.left);
          if (rect.right + shift > window.innerWidth - 12) shift = window.innerWidth - 12 - rect.right;
          if (shift) submenu.style.setProperty('--actools-submenu-shift', `${Math.round(shift)}px`);
        }
      });
    };
    const closeAll = (restoreFocus = false) => {
      const focusedOwner = restoreFocus ? focusOwner() : null;
      const focusInPanel = panel.contains(document.activeElement);
      closeBranches();
      if (!desktop) {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
      }
      if (restoreFocus && focusInPanel) (desktop ? focusedOwner : toggle)?.focus();
      updateMeasurements();
      notify();
    };
    const syncMode = () => {
      if (desktop !== media.matches) {
        const focused = document.activeElement;
        const focusedOwner = focusOwner();
        const focusInPanel = panel.contains(focused);
        const focusOnToggle = focused === toggle;
        desktop = media.matches;
        closeBranches();
        toggle.hidden = desktop;
        // Do not strand a focused desktop item inside a newly hidden panel.
        const open = desktop || (!firstMode && focusInPanel);
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (firstMode && !desktop) {
          branches.forEach((_, button) => {
            if (button.closest('li')?.classList.contains('is-active-trail')) setBranch(button, true);
          });
        }
        if (focusedOwner) focusedOwner.focus();
        if (desktop && focusOnToggle) {
          panel.querySelector('.actools-navigation__link')?.focus();
        }
        firstMode = false;
      }
      updateMeasurements();
      notify();
    };

    // Install handlers before hiding any content or exposing working controls.
    toggle.addEventListener('click', () => {
      if (panel.hidden) {
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        updateMeasurements();
        notify();
      }
      else closeAll(true);
    }, { signal });
    branches.forEach((_, button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        const open = button.getAttribute('aria-expanded') !== 'true';
        if (desktop && open) {
          const list = button.closest('li')?.parentElement;
          branches.forEach((__, sibling) => {
            if (sibling !== button && sibling.closest('li')?.parentElement === list) setBranch(sibling, false);
          });
        }
        setBranch(button, open);
        updateMeasurements();
        notify();
      }, { signal });
    });
    navigation.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      const directButton = event.target.closest('[data-actools-submenu-toggle]');
      const containingMenu = event.target.closest('[data-actools-submenu]');
      const owner = directButton?.getAttribute('aria-expanded') === 'true' ? directButton : branchOwner(containingMenu);
      if (owner) {
        setBranch(owner, false);
        owner.focus();
        updateMeasurements();
        notify();
      }
      else if (!desktop && !panel.hidden) closeAll(true);
      else return;
      event.preventDefault();
      event.stopPropagation();
    }, { signal });
    navigation.addEventListener('click', (event) => {
      const link = event.target.closest('a[href]');
      if (!link || !navigation.contains(link)) return;
      const destination = new URL(link.href, window.location.href);
      if (link.getAttribute('href').includes('#') && destination.origin === location.origin
        && destination.pathname === location.pathname && destination.search === location.search) {
        // Close synchronously so sticky clearance measures the collapsed
        // header before the browser's native hash/history/focus behavior.
        closeAll(false);
        navigation.dispatchEvent(new Event('actools:navigation-before-anchor', { bubbles: true }));
      }
    }, { signal });
    document.addEventListener('click', (event) => {
      if (!navigation.contains(event.target)) closeAll(true);
    }, { signal });
    document.addEventListener('focusin', (event) => {
      if (!navigation.contains(event.target)) closeAll(false);
    }, { signal });
    window.addEventListener('resize', syncMode, { signal });
    window.addEventListener('scroll', updateMeasurements, { signal, passive: true });
    window.visualViewport?.addEventListener('resize', updateMeasurements, { signal });
    media.addEventListener('change', syncMode, { signal });
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(updateMeasurements) : null;
    observer?.observe(toggle);
    // Toolbar trays can move the sticky header without changing its own size.
    // Observe the root's effective admin offset, not the panel/header height,
    // and never dispatch resize events from this observer.
    let adminOffset = getComputedStyle(document.documentElement).getPropertyValue('--actools-header-admin-offset');
    const adminObserver = new MutationObserver(() => {
      const next = getComputedStyle(document.documentElement).getPropertyValue('--actools-header-admin-offset');
      if (next !== adminOffset) {
        adminOffset = next;
        updateMeasurements();
      }
    });
    adminObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['style', 'class'] });
    document.fonts?.ready.then(() => { if (!signal.aborted) updateMeasurements(); });

    branches.forEach((_, button) => { button.hidden = false; });
    navigation.setAttribute('data-actools-nav-enhanced', '');
    syncMode();
    controllers.set(navigation, {
      abort, observer, adminObserver,
      restore() {
        navigation.removeAttribute('data-actools-nav-enhanced');
        toggle.hidden = true;
        toggle.setAttribute('aria-expanded', 'true');
        panel.hidden = false;
        panel.style.removeProperty('--actools-nav-panel-max-height');
        row.style.removeProperty('--actools-nav-toggle-width');
        branches.forEach((submenu, button) => {
          submenu.hidden = false;
          submenu.style.removeProperty('--actools-submenu-shift');
          button.hidden = !button.classList.contains('actools-navigation__disclosure--label');
          button.setAttribute('aria-expanded', 'true');
        });
      },
    });
  };

  Drupal.behaviors.actoolsNavigation = {
    attach(context) {
      once('actools-navigation', navigationsIn(context)).forEach(initialize);
    },
    detach(context, settings, trigger) {
      if (trigger !== 'unload') return;
      once.remove('actools-navigation', navigationsIn(context)).forEach((navigation) => {
        const controller = controllers.get(navigation);
        controller?.abort.abort();
        controller?.observer?.disconnect();
        controller?.adminObserver?.disconnect();
        controller?.restore();
        controllers.delete(navigation);
      });
    },
  };
})(Drupal, once);
