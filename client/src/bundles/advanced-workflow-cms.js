import jQuery from 'jquery';

jQuery.entwine('ss', ($) => {
  $('.cms-edit-form')
    .find('.Actions, .btn-toolbar')
    .find('#ActionMenus_WorkflowOptions .action')
    .entwine({
      onclick(e) {
        const transitionId = this.attr('data-transitionid');
        let buttonName = this.attr('name');

        buttonName = buttonName.replace(/-\d+/, '');
        this.attr('name', buttonName);

        $('input[name=TransitionID]').val(transitionId);

        this._super(e);
      },
    });

  $('.cms-edit-form')
    .find('.Actions, .btn-toolbar')
    .find('.action.start-workflow')
    .entwine({
      onmouseup(e) {
        // Populate the hidden form field with the selected workflow definition.
        $('input[name=TriggeredWorkflowID]').val(this.data('workflow'));

        // Update the element name to exclude the ID, therefore submitting correctly.
        const name = this.attr('name');
        this.attr('name', name.replace(/-\d+/, ''));
        this._super(e);
      },
    });
});
