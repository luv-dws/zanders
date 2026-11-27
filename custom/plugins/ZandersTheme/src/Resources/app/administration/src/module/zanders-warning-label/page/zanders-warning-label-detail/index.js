import template from './zanders-warning-label-detail.html.twig';

const { Component, Mixin } = Shopware;

Component.register('zanders-warning-label-detail', {
    template,

    inject: ['repositoryFactory'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            warningLabel: null,
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    computed: {
        warningLabelRepository() {
            return this.repositoryFactory.create('zanders_warning_label');
        },
    },

    created() {
        this.loadEntity();
    },

    methods: {
        async loadEntity() {
            if (this.$route.params.id) {
                this.isLoading = true;
                try {
                    this.warningLabel = await this.warningLabelRepository.get(
                        this.$route.params.id,
                        Shopware.Context.api
                    );
                } catch (e) {
                    this.createNotificationError({
                        title: 'Error',
                        message: 'Could not load warning label.',
                    });
                } finally {
                    this.isLoading = false;
                }
            } else {
                this.warningLabel = this.warningLabelRepository.create(Shopware.Context.api);
            }
        },

        async onSave() {
            if (!this.warningLabel.name || !this.warningLabel.message) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Please fill in both name and message.',
                });
                return;
            }

            this.isLoading = true;
            try {
                await this.warningLabelRepository.save(this.warningLabel, Shopware.Context.api);
                this.isSaveSuccessful = true;
                this.createNotificationSuccess({
                    title: 'Success',
                    message: 'Warning label saved successfully.',
                });
            } catch (error) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Could not save warning label.',
                });
            } finally {
                this.isLoading = false;
            }
        },

        saveFinish() {
            this.isSaveSuccessful = false;
            this.$router.push({ name: 'zanders.warning.label.index' });
        },
    },
});
