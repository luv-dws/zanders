Shopware.Component.register('sw-cms-el-preview-zanders-popular-products', () => import('./preview'));
Shopware.Component.register('sw-cms-el-zanders-popular-products', () => import('./component'));

Shopware.Service('cmsService').registerCmsElement({
    name: 'zanders-popular-products',
    label: 'Zanders Popular Products',
    component: 'sw-cms-el-zanders-popular-products',
    previewComponent: 'sw-cms-el-preview-zanders-popular-products',
    defaultConfig: {},
});
