/**
 * @file
 * Sticky-header scroll behavior for Actools BS5.
 *
 * CSS provides sticky positioning. JavaScript measures the actual header for
 * native fragment/keyboard scrolling and adds a shadow after the first 10px.
 * The header never registers itself with Drupal.displace: its own top already
 * uses Drupal's admin displacement and counting it there would add it twice.
 *
 * Inside the Drupal Canvas editor preview iframe (page editing and view-mode
 * editing both render the themed page there) a sticky header would detach and
 * overlap the editor chrome, so the sticky is switched off via the
 * `is-canvas-preview` class and the scroll listener is skipped.
 */

((Drupal, once) => {
  const selector = '.page > header[role="banner"]';
  const root = document.documentElement;
  const controllers = new WeakMap();
  let measuredHeader = null;

  const clearMeasurement = (header) => {
    if (measuredHeader !== header) return;
    root.classList.remove('actools-sticky-header-active');
    root.style.removeProperty('--actools-header-height');
    root.style.removeProperty('--actools-header-admin-offset');
    measuredHeader = null;
  };

  const headersIn = (context) => [
    ...(context.matches?.(selector) ? [context] : []),
    ...context.querySelectorAll(selector),
  ];

  // Core Navigation's narrow top bar is in normal flow but still declares
  // data-offset-top. Its stored Drupal offset need not change on every scroll.
  // Measure current viewport geometry, so a bar that scrolls away leaves no gap.
  const adminOffset = (header) => {
    let offset = 0;
    document.querySelectorAll('[data-offset-top]').forEach((element) => {
      if (header.contains(element) || element.contains(header)
        || !element.getClientRects().length) return;
      const bounds = element.getBoundingClientRect();
      if (bounds.bottom <= 0 || bounds.top >= window.innerHeight) return;
      const declared = Number.parseInt(element.getAttribute('data-offset-top'), 10);
      const bottom = Number.isFinite(declared)
        ? Math.min(declared, bounds.bottom) : bounds.bottom;
      offset = Math.max(offset, bottom);
    });
    return Math.ceil(offset);
  };

  // The preview document runs inside an iframe whose host element carries
  // `data-canvas-preview`; same-origin lets us read it from within the frame.
  const inCanvasPreview = () => {
    try {
      return Boolean(
        window.frameElement && 'canvasPreview' in window.frameElement.dataset,
      );
    } catch (e) {
      return false;
    }
  };

  Drupal.behaviors.actoolsBs5StickyHeader = {
    attach(context) {
      // This behavior ships in the sticky-header library, which the theme only
      // attaches when the "Sticky header" setting is enabled — so reaching here
      // already means the feature is on.
      if (inCanvasPreview()) {
        root.classList.add('is-canvas-preview');
        document.querySelectorAll(selector).forEach((header) => {
          controllers.get(header)?.destroy();
        });
        if (measuredHeader) clearMeasurement(measuredHeader);
        return;
      }

      once('actools-bs5-sticky-header', headersIn(context)).forEach((header) => {
        const abort = new AbortController();
        let observer;
        let displacementObserver;
        let frame = null;
        let lastHeight = null;
        let lastOffset = null;

        const measure = () => {
          if (!header.isConnected) {
            controllers.get(header)?.destroy();
            return;
          }
          const height = Math.ceil(header.getBoundingClientRect().height);
          const active = getComputedStyle(header).position === 'sticky'
            && height > 0 && !root.classList.contains('is-canvas-preview');
          if (!active) {
            clearMeasurement(header);
            lastHeight = null;
            lastOffset = null;
            return;
          }
          const offset = adminOffset(header);
          measuredHeader = header;
          root.classList.add('actools-sticky-header-active');
          if (height !== lastHeight) {
            root.style.setProperty('--actools-header-height', `${height}px`);
            lastHeight = height;
          }
          if (offset !== lastOffset) {
            root.style.setProperty('--actools-header-admin-offset', `${offset}px`);
            lastOffset = offset;
          }
        };

        const scheduleMeasure = () => {
          if (frame !== null) return;
          frame = window.requestAnimationFrame(() => {
            frame = null;
            measure();
          });
        };

        const onScroll = () => {
          if (!header.isConnected) {
            controllers.get(header)?.destroy();
            return;
          }
          header.classList.toggle('scrolled', window.scrollY >= 10);
          scheduleMeasure();
        };

        controllers.set(header, {
          destroy() {
            abort.abort();
            observer?.disconnect();
            displacementObserver?.disconnect();
            if (frame !== null) window.cancelAnimationFrame(frame);
            header.classList.remove('scrolled');
            clearMeasurement(header);
            controllers.delete(header);
          },
        });

        window.addEventListener('scroll', onScroll, { passive: true, signal: abort.signal });
        window.addEventListener('resize', measure, { passive: true, signal: abort.signal });
        header.addEventListener('load', measure, { capture: true, signal: abort.signal });
        // A disclosure closes synchronously before the browser follows its
        // fragment link. Refresh clearance in that same event, rather than
        // waiting for ResizeObserver after native scrolling has already run.
        document.addEventListener('actools:navigation-change', measure, { signal: abort.signal });
        document.addEventListener('actools:navigation-before-anchor', measure, { signal: abort.signal });
        if ('ResizeObserver' in window) {
          observer = new ResizeObserver(measure);
          observer.observe(header);
        }
        // Drupal.displace broadcasts through jQuery, but its public CSS custom
        // property is also updated. Watching that property avoids a jQuery
        // dependency and catches toolbar/tray changes without a window resize.
        let reportedOffset = root.style.getPropertyValue('--drupal-displace-offset-top');
        displacementObserver = new MutationObserver(() => {
          const offset = root.style.getPropertyValue('--drupal-displace-offset-top');
          if (offset !== reportedOffset) {
            reportedOffset = offset;
            scheduleMeasure();
          }
        });
        displacementObserver.observe(root, { attributes: true, attributeFilter: ['style'] });
        measure();
        onScroll();
      });
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') return;
      once.remove('actools-bs5-sticky-header', headersIn(context)).forEach((header) => {
        controllers.get(header)?.destroy();
      });
    },
  };
})(Drupal, once);
