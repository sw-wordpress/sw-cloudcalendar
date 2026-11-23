export default {
  lang: 'en-US',
  title: 'SW CloudCalendar',
  description: 'User documentation for read-only calendars in WordPress.',
  base: '/sw-cloudcalendar/',
  srcExclude: ['README.md'],
  head: [['meta', { name: 'theme-color', content: '#3451b2' }]],
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/getting-started' },
      { text: 'Changelog', link: '/Changelog' },
      { text: 'Repository', link: 'https://github.com/sw-wordpress/sw-cloudcalendar' }
    ],
    sidebar: [{ text: 'User guide', items: [
      { text: 'Overview', link: '/' },
      { text: 'Getting started', link: '/getting-started' },
      { text: 'Connections', link: '/connections' },
      { text: 'Sources and attachments', link: '/sources' },
      { text: 'Views and embedding', link: '/views' },
      { text: 'Templates', link: '/templates' },
      { text: 'Configuration and operation', link: '/configuration' },
      { text: 'Troubleshooting', link: '/troubleshooting' },
      { text: 'Changelog', link: '/Changelog' }
    ] }],
    search: { provider: 'local' },
    socialLinks: [{ icon: 'github', link: 'https://github.com/sw-wordpress/sw-cloudcalendar' }],
    editLink: { pattern: 'https://github.com/sw-wordpress/sw-cloudcalendar/edit/master/pages/:path', text: 'Edit this page on GitHub' }
  }
};
