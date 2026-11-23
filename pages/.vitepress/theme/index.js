import { h } from 'vue';
import DefaultTheme from 'vitepress/theme';
import './style.css';

// The default theme hides its footer with a sidebar and omits it on 404 pages.
// A single layout-bottom slot keeps the required wording visible in every layout.
export default {
  extends: DefaultTheme,
  Layout: () => h(DefaultTheme.Layout, null, {
    'layout-bottom': () => h('footer', { class: 'docs-footer' },
      'Open source by Stefan Warnat – free to use, free to change, better together.')
  })
};
