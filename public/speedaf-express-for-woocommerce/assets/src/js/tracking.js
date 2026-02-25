const $ = require('jquery');
import * as remodal from './remodal.js';
class spfService {
     

    constructor(options) {
        this.spfData = options.spfData || window.sdfOrderData;
        var defaults = {
            modalContainer: 'order-tracking-popup',
            modalOptions: {
                hashTracking: false,
                closeOnOutsideClick: false
            },
           
           
            url:this.spfData.ajax_url,
            loading:this.spfData.loading,
            modal: null,
        }
   
        this.settings = Object.assign(defaults,options);
        this.settings.params['action'] = this.spfData.action;
        this.settings.params['_ajax_nonce'] =this.spfData.nonce;
      
            this._init();
            
        
      
    }
    _init(){
        var _this = this;
        if($('#'+this.settings.modalContainer).length === 0){
            $('body').append('<div class="remodal" id="' + this.settings.modalContainer + '" data-remodal-id="' + this.settings.modalContainer + '"><img src="' + _this.settings.loading + '"</div>');
        }
        this.settings.modal = $('[data-remodal-id=order-tracking-popup]');
        
            return this;
            

    }
    modal(call) {
        var _this =this;
        if(!this.settings.modal) this._init();
      
       var modal = this.settings.modal.remodal(this.settings.modalOptions);

        $(document).on('closed', '', function () {
            modal.destroy();

            if (typeof call == 'function')
            call();

        });
        return modal;
    }
    
    tracking() {
        var _this = this;
             this.settings.params['type'] = this.spfData.tracking;
              $(_this.settings.target).attr('disabled','disabled');
            
               this.modal(function(){
                  
                   $(_this.settings.target).removeAttr('disabled');
               }).open();
             this.request();
               
           
            return this;
    }

    printLabel() {
        this.settings.params['type'] = this.spfData.printLabel;
        this.request();
        return this;
    }

    request() {
        var _this = this,params = this.settings.params;
        
        if(typeof params.type == 'undefined' || params.action != this.spfData.action) this.error(this.spfData.Warning,this.spfData.not_found,this.spfData.confirm_button)
       
       
        $.post(this.settings.url,params,function(res){
                if(res.success) {
                    switch(params.type){
                        case _this.spfData.tracking:
                            _this.setModalContent(res.data.tracking_html);
                            $(_this.settings.target).removeAttr('disabled');
                            break;
                        case _this.spfData.printLabel:
                            if(res.data.label_url.length > 0)
                                     window.open(res.data.label_url+ '?response-content-type=application/pdf');
                            
                            break;

                    }
                   
                }else {
                   _this.error(_this.spfData.Warning,res.msg,_this.spfData.confirm_button);
                    $(_this.settings.target).removeAttr('disabled');
                }

                
            });
    }

        setModalContent(content) {
            $('#' + this.settings.modalContainer).html(content);
            return this;
        };
        error(title, content, btn) {
            var _template = '<div style="margin: 1rem 0;"><div class="error-title"><span>' + title + '</span></div><div class="error-content"><div class="error-remodal"><div class="bigIcon" style="background-position: 0px -96px;"></div><div>' + content + '</div></div><div style="text-align:right;"><button data-remodal-action="cancel" class="remodal-confirm">' + btn + '</button></div></div>';
            this.settings.modalOptions = {
                hashTracking: false,
                closeOnOutsideClick: true
            };

           var modal =  this.modal();
            this.setModalContent(_template);
            if(!$('#'+this.settings.modalContainer).hasClass('popup-error')) {
                  $('#'+this.settings.modalContainer).addClass('popup-error');
             }
            modal.open();

        };

}

export {
    spfService
}
