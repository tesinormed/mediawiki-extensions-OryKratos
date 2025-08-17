<?php

namespace MediaWiki\Extension\OryKratos\Maintenance;

use BatchRowIterator;
use MediaWiki\Extension\OryKratos\OryKratos;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class PopulateEquivTable extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'OryKratos' );
		$this->setBatchSize( 100 );
	}

	public function execute(): void {
		$this->output( "populating orykratos_equiv...\n" );

		$iterator = new BatchRowIterator(
			$this->getReplicaDB(),
			table: 'user',
			primaryKey: 'user_id',
			batchSize: $this->getBatchSize()
		);
		$iterator->setFetchColumns( [ 'user_name' ] );
		$iterator->setCaller( __METHOD__ );

		$tempUserConfig = $this->getServiceContainer()->getTempUserConfig();

		$count = 0;
		foreach ( $iterator as $batch ) {
			$usernames = array_map(
				static fn ( $row ) => [
					'equiv_user' => $row->user_id,
					'equiv_normalized' => OryKratos::getEquivset()->normalize( $row->user_name )
				],
				array_filter(
				$batch,
				static fn ( $row ) => !$tempUserConfig->isTempName( $row->user_name )
			) );

			$this->getPrimaryDB()->newInsertQueryBuilder()
				->insertInto( 'orykratos_equiv' )
				->ignore()
				->rows( $usernames )
				->caller( __METHOD__ )->execute();

			$count += count( $usernames );
			$this->output( "$count...\n" );
			$this->waitForReplication();
		}

		$this->output( "$count user(s) have been processed.\n" );
	}
}

$maintClass = PopulateEquivTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
