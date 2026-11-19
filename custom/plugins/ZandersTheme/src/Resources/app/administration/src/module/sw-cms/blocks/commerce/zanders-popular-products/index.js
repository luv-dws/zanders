Shopware.Component.register('sw-cms-preview-zanders-popular-products', () => import('./preview'));
Shopware.Component.register('sw-cms-block-zanders-popular-products', () => import('./component'));

Shopware.Service('cmsService').registerCmsBlock({
    name: 'zanders-popular-products',
    label: 'Zanders Popular Products',
    category: 'commerce',
    component: 'sw-cms-block-zanders-popular-products',
    previewComponent: 'sw-cms-preview-zanders-popular-products',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: null,
        marginRight: null,
        sizingMode: 'boxed',
    },
    slots: {
        content: 'zanders-popular-products',
    },
});
