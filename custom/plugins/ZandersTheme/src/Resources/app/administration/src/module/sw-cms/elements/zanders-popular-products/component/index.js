import template from './sw-cms-el-zanders-popular-products.html.twig';
import './sw-cms-el-zanders-popular-products.scss';

const { Mixin } = Shopware;

export default {
    template,
    mixins: [
        Mixin.getByName('cms-element'),
    ],
};
