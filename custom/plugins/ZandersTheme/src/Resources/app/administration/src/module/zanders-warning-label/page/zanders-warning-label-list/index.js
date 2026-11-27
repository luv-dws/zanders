import template from './zanders-warning-label-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('zanders-warning-label-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            entityList: null,
            isLoading: false,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            total: 0,
            showDeleteModal: false,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        warningLabelRepository() {
            return this.repositoryFactory.create('zanders_warning_label');
        },

        columns() {
            return [
                {
                    property: 'name',
                    label: 'Name / Title',
                    routerLink: 'zanders.warning.label.detail',
                    primary: true,
                    allowResize: true,
                },
                {
                    property: 'message',
                    label: 'Alert Message',
                    allowResize: true,
                },
                {
                    property: 'url',
                    label: 'Website URL',
                    allowResize: true,
                },
                {
                    property: 'createdAt',
                    label: 'Created At',
                    allowResize: true,
                },
            ];
        },
    },

    created() {
        this.getList();
    },

    methods: {
        async getList() {
            this.isLoading = true;
            try {
                const criteria = new Criteria(this.page || 1, this.limit || 25);
                if (this.term) {
                    criteria.setTerm(this.term);
                }
                const sortField = this.currentSortBy || this.sortBy || 'createdAt';
                criteria.addSorting(Criteria.sort(sortField, this.sortDirection || 'DESC', this.naturalSorting || false));

                const result = await this.warningLabelRepository.search(criteria, Shopware.Context.api);
                this.entityList = result;
                this.total = result.total;
            } catch (error) {
                console.error('[zanders-warning-label-list] Failed to fetch:', error);
                this.createNotificationError({
                    title: 'Error',
                    message: 'Could not load warning labels.',
                });
            } finally {
                this.isLoading = false;
            }
        },

        onDelete(id) {
            this.showDeleteModal = id;
        },

        onCloseDeleteModal() {
            this.showDeleteModal = false;
        },

        async onConfirmDelete(id) {
            this.showDeleteModal = false;
            this.isLoading = true;
            try {
                await this.warningLabelRepository.delete(id, Shopware.Context.api);
                this.createNotificationSuccess({
                    title: 'Success',
                    message: 'Warning label deleted successfully.',
                });
                await this.getList();
            } catch (error) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Could not delete warning label.',
                });
                this.isLoading = false;
            }
        },
    },
});
