
jQuery(document).ready(function($) {	
	
        $.widget( "custom.catcomplete", $.ui.autocomplete, {
          _create: function() {
            this._super();
            this.widget().menu( "option", "items", "> :not(.ui-autocomplete-category)" );
          },
          _renderMenu: function( ul, items ) {
              var that = this, currentCategory = "";
              $.each( items, function( index, item ) {
                  var li;
                  if ( item.category != currentCategory ) {
                      ul.append( "<li class='ui-autocomplete-category'>" + item.category + "</li>" );
                      //ul.append( "<span class='ui-autocomplete-category'>" + item.category + "</span>" );
                      //ul.append( item.category);
                      currentCategory = item.category;
                  }
                  li = that._renderItemData( ul, item );
                  if ( item.category ) {
                      li.attr( "aria-label", item.category + " : " + item.label );
                  }
              });
          }
        });
        
        var url = MyAutoComplete.url + "?action=echoRedirectToPages";
        var url = "admin-ajax.php?action=echoRedirectToPages";
        $( "#redirect_to_user_field" ).catcomplete({
                source: url,
                delay: 500,
                minLength: 0,
                select: function(event, ui) {
                    event.preventDefault();
                    $("#redirect_to_user_field").val(ui.item.label);
                    $("#redirect_to_data_field").val(ui.item.value);
                    // TODO change redirect_to_user_field_explanation to say "page selected" or "external URL entered"
                },
                focus: function(event, ui) {
                    // don't change the contents of the textbox just by highlighting something.
                    event.preventDefault();
                }

        });	

});
