if(typeof ExternalModulesOptional === 'undefined') {
    var ExternalModulesOptional = {};
}


ExternalModulesOptional.customTextAlert = function(textSelector) {
    textSelector.focus(function() {
        console.log($(this).val());
    });
};


ExternalModulesOptional.getChildEvent = function(textSelector) {
    console.log("CALLED!");

    textSelector.focus(function() {
        console.log("Look up events", textSelector, $(this).val());
    });
};

