import jQuery from 'jquery';
import i18n from 'i18n';

jQuery.entwine('workflow', ($) => {
  $('.workflow-field').entwine({
    Loading: null,
    Dialog: null,
    onmatch() {
      const self = this;

      this.setLoading(this.find('.workflow-field-loading'));
      this.setDialog(this.find('.workflow-field-dialog'));

      this.getDialog().data('workflow-field', this).dialog({
        autoOpen: false,
        width: 800,
        height: 600,
        modal: true,
        dialogClass: 'workflow-field-editor-dialog',
        draggable: false,
      });

      this.getDialog().on('click', 'button', (event) => {
        $(event.currentTarget).addClass('disabled');
      });

      this.getDialog().on('submit', 'form', (event) => {
        const form = event.currentTarget;
        const data = new FormData(form);
        // Include the button that submitted the form, which tells the form handler which action to run
        const { submitter } = event.originalEvent || {};
        if (submitter && submitter.name) {
          data.append(submitter.name, submitter.value);
        }

        $.ajax({
          url: form.action,
          type: form.method || 'POST',
          data,
          processData: false,
          contentType: false,
        }).done((response) => {
          if ($(response).is('.workflow-field')) {
            self.getDialog().empty().dialog('close');
            self.replaceWith(response);
          } else {
            self.getDialog().html(response);
          }
        });

        return false;
      });
    },

    onunmatch() {
      $('.workflow-field-editor-dialog').remove();
    },

    showDialog(url, title = null) {
      const dlg = this.getDialog();

      dlg.empty().dialog('option', 'title', title);
      dlg.empty().dialog('open');
      dlg.parent().addClass('loading');

      $.get(url).done((body) => {
        dlg.html(body).parent().removeClass('loading');
      });
    },

    loading(toggle) {
      this.getLoading().toggle(typeof toggle === 'undefined' || toggle);
    },
  });

  $('.workflow-field .workflow-field-actions').entwine({
    onmatch() {
      $('.workflow-field .workflow-field-action-disabled').on('click', () => false);

      this.sortable({
        axis: 'y',
        containment: this,
        placeholder: 'ui-state-highlight workflow-placeholder',
        handle: '.workflow-field-action-drag',
        tolerance: 'pointer',
        update() {
          const actions = $(this).find('.workflow-field-action');
          const field = $(this).closest('.workflow-field');
          const link = field.data('sort-link');
          const ids = actions.map((index, element) => $(element).data('id'));

          const data = {
            'id[]': ids.get(),
            class: 'Symbiote\\AdvancedWorkflow\\DataObjects\\WorkflowAction',
            SecurityID: field.data('securityid'),
          };

          field.loading();
          $.post(link, data).done(() => { field.loading(false); });
        },
      });
    },
  });

  $('.workflow-field .workflow-field-action-transitions').entwine({
    onmatch() {
      this.sortable({
        axis: 'y',
        containment: this,
        handle: '.workflow-field-action-drag',
        tolerance: 'pointer',
        update() {
          const trans = $(this).find('li');
          const field = $(this).closest('.workflow-field');
          const link = field.data('sort-link');
          const ids = trans.map((index, element) => $(element).data('id'));

          const data = {
            'id[]': ids.get(),
            class: 'Symbiote\\AdvancedWorkflow\\DataObjects\\WorkflowTransition',
            parent: $(this).closest('.workflow-field-action').data('id'),
            SecurityID: field.data('securityid'),
          };

          field.loading();
          $.post(link, data).done(() => { field.loading(false); });
        },
      });
    },
  });

  $('.workflow-field .workflow-field-create-class').entwine({
    onmatch() {
      this.chosen().addClass('has-chnz');
    },

    onchange() {
      this.siblings('.workflow-field-do-create').toggleClass('disabled', !this.val());
    },
  });

  $('.workflow-field .workflow-field-do-create').entwine({
    onclick() {
      const sel = this.siblings('.workflow-field-create-class');
      const field = this.closest('.workflow-field');

      if (sel.val()) {
        field.showDialog(sel.val(), i18n._t('Workflow.CreateWorkflow', 'Create workflow'));
      }

      return false;
    },
  });

  $('.workflow-field .workflow-field-open-dialog').entwine({
    onclick() {
      this.closest('.workflow-field').showDialog(this.prop('href'), this.data('modalTitle'));
      return false;
    },
  });

  $('.workflow-field .workflow-field-delete').entwine({
    onclick() {
      // eslint-disable-next-line no-alert
      if (confirm(i18n._t('Workflow.DeleteQuestion'))) {
        const data = {
          SecurityID: this.data('securityid'),
        };

        $.post(this.prop('href'), data).done((body) => {
          $('.workflow-field').replaceWith(body);
        });
      }

      return false;
    },
  });

  $('#Root_PublishingSchedule').entwine({
    onmatch() {
      const self = this;
      const publishDate = this.find('input[name="PublishOnDate[date]"]');
      const publishTime = this.find('input[name="PublishOnDate[time]"]');
      const parent = publishDate.parent().parent();

      if (!$('#Form_EditForm_action_publish').attr('disabled')) {
        self.checkEmbargo($(publishDate).val(), $(publishTime).val(), parent);

        publishDate.change(() => {
          self.checkEmbargo($(publishDate).val(), $(publishTime).val(), parent);
        });

        publishTime.change(() => {
          self.checkEmbargo($(publishDate).val(), $(publishTime).val(), parent);
        });
      }

      this._super();
    },

    /*
     * Helper function opens publishing schedule tab when link clicked
     */
    linkScheduled(parent) {
      $('#workflow-schedule').click(() => {
        const tabID = parent.closest('.ui-tabs-panel.tab').attr('id');
        $(`#tab-${tabID}`).trigger('click');
        return false;
      });
    },

    /*
     * Checks whether an embargo is present.
     * If an embargo is present, display an altered actions panel,
     * with a message notifying the user
     */
    checkEmbargo(publishDate, publishTime, parent) {
      // Something has changed, remove any existing embargo message
      $('.Actions #embargo-message').remove();

      /*
       * Fuzzy checking:
       * There may not be $(#PublishOnXXX input.date) DOM objects = undefined.
       * There may be $(#PublishOnXXX input.date) DOM objects = val() method may return zero-length.
       */
      const noPublishDate = (publishDate === undefined || publishDate.length === 0);
      const noPublishTime = (publishTime === undefined || publishTime.length === 0);

      if (noPublishDate && noPublishTime) {
        // No Embargo, remove customizations
        $('#Form_EditForm_action_publish').removeClass('embargo');
        $('#Form_EditForm_action_publish').prev('button').removeClass('ui-corner-right');
      } else {
        let message = '';

        $('#Form_EditForm_action_publish').addClass('embargo');
        $('#Form_EditForm_action_publish').prev('button').addClass('ui-corner-right');

        if (publishDate === '') {
          // Has time, not date
          message = i18n.sprintf(
            i18n._t('Workflow.EMBARGOMESSAGETIME'),
            publishTime
          );
        } else if (publishTime === '') {
          // has date no time
          message = i18n.sprintf(
            i18n._t('Workflow.EMBARGOMESSAGEDATE'),
            publishDate
          );
        } else {
          // has date and time
          message = i18n.sprintf(
            i18n._t('Workflow.EMBARGOMESSAGEDATETIME'),
            publishDate,
            publishTime
          );
        }

        message = message.replace('<a>', '<a href="#" id="workflow-schedule">');

        // Append message with link
        $('.Actions #ActionMenus')
          .after(`<p class="edit-info" id="embargo-message">${message}</p>`);

        // Active link
        this.linkScheduled(parent);
      }

      return false;
    },
  });
});

jQuery.entwine('ss', ($) => {
  // Hide the uneccesary "Show Specification..." link included on ImportForms by default
  $('.importSpec').entwine({
    onmatch() {
      this.hide();
    },
  });

  // Remove the somehat hard-coded 'CSV' string from error message
  $('#Form_ImportForm_error').entwine({
    onmatch() {
      this.html(this.html().replace('CSV', ''));
    },
  });

  /**
   * Prevents actions from causing an ajax reload of the field.
   *
   * This is specific to workflow export logic, where we don't want an AJAX request
   * interfering with browser download headers.
   */
  $('.grid-field .action.no-ajax, .grid-field .no-ajax .action:button').entwine({
    onclick(e) {
      if (!this.hasClass('export-link')) {
        return this._super(e);
      }

      window.location.href = $.path.makeUrlAbsolute(this.attr('href'));

      return false;
    },
  });
});
