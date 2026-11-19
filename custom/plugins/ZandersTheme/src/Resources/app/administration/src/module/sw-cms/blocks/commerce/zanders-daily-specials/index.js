Shopware.Component.register('sw-cms-preview-zanders-daily-specials', () => import('./preview'));
Shopware.Component.register('sw-cms-block-zanders-daily-specials', () => import('./component'));

Shopware.Service('cmsService').registerCmsBlock({
    name: 'zanders-daily-specials',
    label: 'Zanders Daily Specials',
    category: 'commerce',
    component: 'sw-cms-block-zanders-daily-specials',
    previewComponent: 'sw-cms-preview-zanders-daily-specials',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: null,
        marginRight: null,
        sizingMode: 'boxed',
    },
    slots: {
        content: 'zanders-daily-specials',
    },
});
