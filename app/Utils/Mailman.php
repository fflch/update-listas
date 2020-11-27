<?php 

namespace App\Utils;

use splattner\mailmanapi\MailmanAPI;
use Uspdev\Replicado\DB;
use Uspdev\Cache\Cache;
use App\Rules\MultipleEmailRule;

class Mailman
{
    public static function update(Lista $lista)
    {
        /* Emails do replicado */
        $cache = new Cache();
        $result = $cache->getCached('\Uspdev\Replicado\DB::fetchAll',$lista->replicado_query);
        $emails_replicado = array_column($result, 'codema');

        /* Emails adicionais */
        if(empty($lista->emails_adicionais)) {
            $emails_adicionais = [];
        }
        else {
            $emails_adicionais = explode(',',$lista->emails_adicionais);
        }
        $emails_updated = array_merge($emails_replicado,$emails_adicionais);

        /* Agora vamos no mailman */
        $url = $lista->url_mailman . '/' . $lista->name;
        $mailman = new MailmanAPI($url,$lista->pass,false);

        /* Emails da lista */
        $emails_mailman = $mailman->getMemberlist();

        /* Emails que estão no replicado+adicionais, mas não na lista
         * Serão inseridos na lista
         */
        $to_add = array_diff($emails_updated,$emails_mailman);

        /* Emails allowed não podem fazer parte das listas */
        $emails_allowed = explode(',',$lista->emails_allowed);
        $to_add = array_diff($to_add,$emails_allowed);

        /* Emails que estão na lista, mas não no replicado+adicionais
         * Serão removidos na lista
         */
        $to_remove = array_diff($emails_mailman,$emails_updated);

        /* remove olders */
        $to_remove = array_map( 'trim', $to_remove );
        $mailman->removeMembers($to_remove);

        /* add news */
        $to_add = array_map( 'trim', $to_add );
        $to_add = array_unique($to_add);
        $mailman->addMembers($to_add);

        /* update stats */
        $lista->stat_mailman_before = count($emails_mailman);
        $lista->stat_mailman_after = count($emails_mailman)+count($to_add)-count($to_remove);
        $lista->stat_mailman_added = count($to_add);
        $lista->stat_mailman_removed = count($to_remove);
        $lista->stat_mailman_replicado = count($emails_replicado);
        $lista->stat_replicado_updated = count($emails_replicado);
        $lista->stat_mailman_date = date('Y-m-d H:i:s');
        $lista->save();

        $request->session()->flash('alert-success',
            count($to_remove) . " emails removidos e " .
            count($to_add) . " adicionados.");

        return redirect("/listas/$lista->id");
    }

    private function setConfigMailman($lista) {
        if(!empty($lista->pass) && !empty($lista->url_mailman) && !empty($lista->name)) {
            $owner = 'fflchsti@usp.br';
            $url = $lista->url_mailman . '/' . $lista->name;
            $mailman = new MailmanAPI($url,$lista->pass,false);
            $mailman->configPrivacySender(explode(',',$lista->emails_allowed));
            $mailman->configGeneral($lista->name,$owner,$lista->name);

            /* Os métodos abaixo estão funcionando, porém, são muitas
               requisições. Quando o apache2 do mailman nos bloqueia
               é retornado null.

            $mailman->configGeneral($lista->name,$owner,ucfirst($lista->name));
            $mailman->configPrivacySubscribing();
            $mailman->configPrivacyRecipient();
            $mailman->configDigest();
            $mailman->configNonDigest();
            $mailman->configBounce();
            */            
           
        }
    }
}