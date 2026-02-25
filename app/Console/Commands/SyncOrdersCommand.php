<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\ImportController;

class SyncOrdersCommand extends Command
{
    // Nom et signature de la commande (artisan command)
    protected $signature = 'sync:orders';

    // Description de la commande
    protected $description = 'Synchronisation des commandes';

    public function __construct()
    {
        parent::__construct();
    }

    // Méthode exécutée lorsque la commande est lancée
    public function handle()
    {
        try {
            // Instancier le contrôleur et appeler la méthode sync()
            $importController = new ImportController();
            $importController->sync();

            $this->info('Synchronisation des commandes effectuée avec succès.');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error('Erreur de connexion lors de la synchronisation: ' . $e->getMessage());
            $this->info('La synchronisation a échoué à cause d\'un problème de réseau.');
            return 1; // Exit with error code
        } catch (\Exception $e) {
            $this->error('Erreur lors de la synchronisation: ' . $e->getMessage());
            return 1; // Exit with error code
        }
        
        return 0; // Success
    }
}
