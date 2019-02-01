
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
                      currentCategory = item.category;
                  }
                  li = that._renderItemData( ul, item );
                  if ( item.category ) {
                      li.attr( "aria-label", item.category + " : " + item.label );
                  }
                  li.addClass(item.depth);
              });
          }
        });
        
        var url = MyAutoComplete.url + "?action=echoRedirectToPages";
        var url = "admin-ajax.php?action=echoRedirectToPages";
        var cache = {};
        $( "#redirect_to_user_field" ).catcomplete({
                //source: url,
                source: function( request, response ) {
                            var term = request.term;
                            if ( term in cache ) {
                            response( cache[ term ] );
                            return;
                        }
                        $.getJSON( url, request, function( data, status, xhr ) {
                            cache[ term ] = data;
                            response( data );
                        });
                    },
                delay: 500,
                minLength: 0,
                select: function(event, ui) {
                    event.preventDefault();
                    $("#redirect_to_user_field").val(ui.item.label);
                    $("#redirect_to_data_field_title").val(ui.item.label);
                    $("#redirect_to_data_field_id").val(ui.item.value);
                    
                    // TODO change redirect_to_user_field_explanation to say "page selected" or "external URL entered"
                },
                focus: function(event, ui) {
                    // don't change the contents of the textbox just by highlighting something.
                    event.preventDefault();
                },
                change: function( event, ui ) {
                    if ( !ui.item ) {
                        // if no item was selected then we force the search box to change back to 
                        // whatever the user previously selected.
                        $selectedVal = $('#redirect_to_data_field_title').val();
                        $("#redirect_to_user_field").val($selectedVal);
                        event.preventDefault();
                    }
                }
        });
        
        // prevent / disable the enter key for the search box.
        $('#redirect_to_user_field').keypress(function(event) {
            if (event.keyCode == 13) {
                event.preventDefault();
            }
        });

});
