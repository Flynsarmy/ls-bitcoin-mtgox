<?

	class FlynsarmyMtGox_Module extends Core_ModuleBase
	{
		public static $debug = false;

		protected function createModuleInfo()
		{
			return new Core_ModuleInfo(
				"Mt.Gox Bitcoin Payment Processor",
				"Adds Mt.Gox bitcoin payment method to your store.",
				"Flynsarmy"
			);
		}

		public static function log( $message )
		{
			if ( self::$debug )
				traceLog('FlynsarmyMtGox: ' . $message);
		}
	}
