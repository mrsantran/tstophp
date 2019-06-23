<?php
namespace tptophp {

	use phptojs\util\OBFileWriter;
	use Nette\PhpGenerator\ClassType;

	require_once __DIR__ . "/OBFileWriter.php";

	class UnexpectedSyntaxException extends \Exception{
		private $lineStr="";

		/**
		 * UnexpectedSyntaxException constructor.
		 * @param string $file
		 * @param int $line
		 * @param string $lineStr
		 * @param $code
		 */
		function __construct($file,$line,$lineStr,$code) {
			parent::__construct("Unexpected syntax");
			$this->file=$file;
			$this->line=$line;
			$this->lineStr=$lineStr;
			$this->code=$code;
		}
		public function getOriginalLine(){
			return $this->lineStr;
		}
		
		public function getFullMessage(){
			return $this->getMessage().PHP_EOL."Code: ".$this->getCode().PHP_EOL."File: ".$this->getFile().PHP_EOL.
				"Line: ".$this->getLine().PHP_EOL."Original Line: ".$this->getOriginalLine();
		}
	}

	class Converter{
		private static $self=null;
		/**
		 * @var OBFileWriter
		 */
		private $obfw;
		private $actualFileName;
		private $indentNum=0;
		private $lines=[];
		private $lineNum=0;
		private $reservedKeyword=["default","function","eval","array"];
		private $replaceTypes=[
			"any"=>"mixed",
			"function"=>"callable"
		];
		private $existedClasses=[];
		private $currentNamespace="";
		private $knownNamespaceClasses=[];
		private $isPreprocess=false;

		public static function convert($tsFilePath,$exportFilePath){
			if (self::$self==null){
				self::$self=new self();
			}

			self::$self->existedClasses=[];
			self::$self->actualFileName=basename($tsFilePath);
			self::$self->obfw = new OBFileWriter($exportFilePath);

			self::$self->lines = file($tsFilePath);
			self::$self->lineNum=0;
			self::$self->indentNum=0;
			self::$self->currentNamespace="";
			self::$self->knownNamespaceClasses=[];
			try {
				self::$self->isPreprocess=true;
				self::$self->obfw->start(true);
				self::$self->_convert();
				self::$self->obfw->end();
				self::$self->isPreprocess=false;

				self::$self->existedClasses=[];
				self::$self->lineNum=0;
				self::$self->indentNum=0;
				self::$self->currentNamespace="";
				self::$self->obfw->start();
				self::$self->_convert();
			}catch (UnexpectedSyntaxException $e){
				self::$self->obfw->end();
				throw $e;
			}
			self::$self->obfw->end();
			
		}
		
		private function getNextLine(){
			if (count($this->lines)<=$this->lineNum){
				return false;
			}
			return trim($this->lines[$this->lineNum++]);
		}
		private function getCurrentLine(){
			return trim($this->lines[$this->lineNum]);
		}
		private function getPreviousLine(){
			return trim($this->lines[$this->lineNum-1]);
		}
		
		private function _convert() {

			echo "<?php" . PHP_EOL;
			while (($line=$this->getNextLine())!==false) {
				if (substr($line, 0, 2) == "/*") {
					do {
						echo $line.PHP_EOL;
						if (trim(substr($line,-2))=="*/"){
							break;
						}
					}while (($line=$this->getNextLine())!==false);
					continue;
				}
				if (substr($line, 0, 11) == "declare var" || substr($line, 0, 3) == "var") {
					echo "namespace {" . PHP_EOL;
					$this->currentNamespace="";
					$indent = $this->indent();
					$line=trim(str_replace(["var","declare"],"",$line));
					$parts = explode(":",$line);
					if (count($parts)==2 && trim($parts[1])=="Function"){
						$this->checkReservedKeyword($name);
						echo "{$indent}function {$name}(){}" . PHP_EOL;
						continue;
					}else
						if (count($parts)==2){
							if (strpos($line,"{")!==false){
								$this->indent();
								$this->parseClass();
								$this->oudent();
							}else {
								$name = trim($parts[0]);
								$this->checkReservedKeyword($name);
								echo "{$indent}/**" . PHP_EOL;
								echo "{$indent} * @const " . trim(str_replace(".", "\\", $parts[1])) . PHP_EOL;
								echo "{$indent} */" . PHP_EOL;
								echo "{$indent}const {$name}=null;" . PHP_EOL;
							}
						}
					$this->oudent();
					echo "}" . PHP_EOL;
					continue;
				}
				if (substr($line, 0, 2) == "//") {
					echo $line . PHP_EOL;
					continue;
				}
				$line = trim($line);
				if (strlen($line) == 0) {
					echo PHP_EOL;
					continue;
				}
				if (substr($line, 0, 14) == "declare module") {
					$this->parseModule();
					continue;
				}
				if (substr($line, 0, 13) == "declare class" || substr($line, 0, 9) == "interface") {
					echo "namespace {" . PHP_EOL;
					$this->currentNamespace="";
					$this->indent();
					$this->parseClass();
					$this->oudent();
					echo "}" . PHP_EOL;
					continue;
				}
				if (substr($line, 0, 12) == "declare type" && (strpos($line,"=>")!==false || strpos($line,"|")!==false)) {
					continue;
				}
				if (substr($line, 0, 16) == "declare function" || substr($line, 0, 15) == "export function") {
					echo "namespace {" . PHP_EOL;
					$this->currentNamespace="";
					$this->indent();
					$this->parseFunction();
					$this->oudent();
					echo "}" . PHP_EOL;
					continue;
				}

				throw new UnexpectedSyntaxException($this->actualFileName,$this->lineNum+1,$line,2);
			}
		}
		
		private function checkReservedKeyword(&$name,$checkExist=true){
			foreach($this->reservedKeyword as $keyword){
				if (strtolower(trim($name))==$keyword){
					$name=trim($name)."_";
					break;
				}
			}
			if (trim($name)=="\$"){
				$name="_";
			}
			if ($checkExist) {
				if (!array_key_exists($this->currentNamespace,$this->existedClasses)){
					$this->existedClasses[$this->currentNamespace]=[];
				}
				while (in_array(strtolower(trim($name)), $this->existedClasses[$this->currentNamespace])) {
					$name = trim($name) . "_";
				}
				$this->existedClasses[$this->currentNamespace][] = strtolower(trim($name));
			}
		}

		private function indent() {
			return str_repeat("\t", ++$this->indentNum);
		}

		private function getIndent() {
			return str_repeat("\t", $this->indentNum);
		}

		private function oudent() {
			return str_repeat("\t", --$this->indentNum);
		}
		private function parseModule($currentNamespace = []) {
			$indent = $this->getIndent();
			$line = $this->getPreviousLine();
			$line = str_replace(["declare", "module", "{", '"', "'"], "", $line);
			$namespaces = explode(".",$line);
			foreach($namespaces as $namespace){
				$currentNamespace[]=trim($namespace);
			}
			echo $indent . "namespace " . join("\\", $currentNamespace) . " {" . PHP_EOL;
			$this->currentNamespace = join("\\",$currentNamespace);
			$indent = $this->indent();
			while (($line= $this->getNextLine()) !==false) {
				if (substr($line,0,2)=="//"){
					echo $indent.$line.PHP_EOL;
					continue;
				}
				if ($line==""){
					echo PHP_EOL;
					continue;
				}
				if ($line=="/**" || substr($line,0,1)=="*" || $line=="**/"){
					echo $indent.$line.PHP_EOL;
					continue;
				}

				if (substr($line, 0, 15) == "export function") {
					$this->parseFunction();
					continue;
				}
				if (substr($line, 0, 5) == "class" || substr($line, 0, 9) == "interface") {
					$this->parseClass();
					continue;
				}
				if (trim($line) == "}") {
					break;
				}
				if (substr($line, 0, 6) == "module") {
					$oldIndentNum= $this->indentNum;
					$this->indentNum=0;
					$this->getIndent();
					echo $indent."}".PHP_EOL;
					$this->parseModule($currentNamespace);
					echo $indent . "namespace " . join("\\", $currentNamespace) . " {" . PHP_EOL;
					$this->currentNamespace = join("\\",$currentNamespace);
					$this->indentNum=$oldIndentNum;
					$this->getIndent();
					continue;
				}
				if (substr($line, 0, 3) == "var" || substr($line, 0, 10) == "export var") {
					$line=trim(str_replace(["var","export"],"",$line));
					$parts = explode(":",$line);
					if (count($parts)==2 && trim($parts[1])=="Function"){
						$name=$parts[0];
						$this->checkReservedKeyword($name);
						echo $indent."function {$name}(){}".PHP_EOL;
						continue;
					}
					if (count($parts)==2){
						if (strpos($line,"{")!==false){
							$var = $this->parseArray();
							echo "const ".$parts[0]."=".json_encode($var).";";
							continue;
						}else {
							$name = trim($parts[0]);
							$this->checkReservedKeyword($name);
							echo "{$indent}/**" . PHP_EOL;
							echo "{$indent} * @const " . trim(str_replace(".", "\\", $parts[1])) . PHP_EOL;
							echo "{$indent} */" . PHP_EOL;
							echo "{$indent}const {$name}=null;" . PHP_EOL;
							continue;
						}
					}
					continue;
				}
				if (substr($line, 0, 4) == "enum" || substr($line, 0, 11) == "export enum") {
					$this->parseEnum();
					continue;
				}
				if (substr($line, 0, 6) == "export") {
					if (strpos($line,"=")!==false) {
						$line = str_replace(["export", "=", " ", ";"], "", $line);
						$this->checkReservedKeyword($line);
						echo $indent . "class {$line} {}" . PHP_EOL;
						continue;
					}else{
						$this->parseClass();
						continue;
					}
				}

				throw new UnexpectedSyntaxException($this->actualFileName,$this->lineNum+1,$line,3);
			}
			$this->oudent();
			echo "}".PHP_EOL;
		}
		
		private function parseEnum() {
			$indent = $this->getIndent();
			$line = $this->getPreviousLine();
			$line = str_replace(["export","enum","{",], "", $line);
			$line = trim($line);

			$this->addClassNamespace($line);
			$class = new ClassType($line);

			$enumNum=0;
			while (($line = $this->getNextLine()) !==false){
				$line = trim(str_replace([","],"",$line));
				if ($line==""){
					continue;
				}

				if ($line=="}"){
					break;
				}

				$class->addConst($line,$enumNum++);
			}

			$class = $class->__toString();
			echo preg_replace("/^(.*)/m",$indent."$1",$class);
			
		}
		
		private function parseClass() {
			$indent = $this->getIndent();
			$line = $this->getPreviousLine();
			while (($pos=strpos($line,"<"))!==false && ($lastPos=strpos($line,">"))!==false){
				$line=substr($line,0,$pos).substr($line,$lastPos+1);
			}
			$isInterface = strpos($line,"interface")!==false;
			$isExtend = strpos($line,"extends")!==false;
			$isImplements = strpos($line,"implements")!==false;
			$implementsTypes=[];
			if ($isImplements){
				$pos = strpos($line,"implements");
				$lastPos = strpos($line,"{");
				$implTypes = trim(substr($line,$pos+10,$lastPos-$pos+10));
				$implTypes = explode(",",$implTypes);
				foreach($implTypes as $type){
					$type=str_replace(["{"],"",$type);
					$implementsTypes[]=trim($type);
				}
				$line=substr($line,0,$pos);
			}
			$classType="";
			if ($isExtend){
				$pos = strpos($line,"extends");
				$lastPos = strpos($line,"implements");
				if ($lastPos===false){
					$lastPos = strpos($line,"{");
				}
				$classType=trim(substr($line,$pos+7,$lastPos-$pos+7));
				$classType=str_replace(["{"],"",$classType);
				$line=substr($line,0,$pos);
			}
			$line = str_replace(["export","declare", "class", "interface", "extends", "{", '"', "'",":","var"], "", $line);
			$line = trim($line);
			$this->checkReservedKeyword($line);
			$this->addClassNamespace($line);
			$class = new ClassType($line);
			if ($isInterface) {
				$class->setType('interface');
			}
			if ($isExtend){
				$this->checkReservedKeyword($classType,false);
				$this->checkClassNamespace($classType);
				$class->addExtend($classType);
			}
			if ($isImplements){
				foreach($implementsTypes as $type){
					$this->checkReservedKeyword($type,false);
					$this->checkClassNamespace($type);
					$class->addImplement($type);
				}
			}

			$currentComment=[];
			$currentCommentParams=[];
			$currentCommentReturn="";
			$knownMethods=[];
			while (($line = $this->getNextLine()) !== false) {
				if (strlen($line)==0){
					continue;
				}
				if ($line=="/**" || substr($line,0,3)=="/**"){
					$currentComment=[];
					$currentCommentParams=[];
					$currentCommentReturn="";
					if ($line!="/**"){
						if (substr($line,-2)=="*/"){
							$line=substr($line,0,-2);
						}
						$line=substr($line,3);
						$currentComment[]=$line;
					}
					continue;
				}
				if ($line=="*/"){
					continue;
				}
				if (substr($line,0,1)=="*"){
					$line = trim(substr($line,1));
					if (substr($line,0,6)=="@param"){
						$line = trim(substr($line,6));
						$currentCommentParams[]=$line;
						continue;
					}
					if (substr($line,0,7)=="@return"){
						$line = trim(substr($line,7));
						$currentCommentReturn=$line;
						continue;
					}
					$currentComment[]=$line;
					continue;
				}
				if (substr($line,0,2)=="//"){
					continue;
				}

				$isStatic=false;

				if ($line=="}" || $line=="};"){
					break;
				}


				if (substr($line,0,3)=="new"){
					continue;
				}
				if (substr($line,0,1)=="(" || substr($line,0,1)=="<" || substr($line,0,1)=="["){
					continue;
				}
				if (strpos($line,"(")!==false && strpos($line,")")!==false && strpos($line,"=>")!==false){
					continue;
				}
				if (substr($line,0,6)=="static"){
					$isStatic=true;
					$line=trim(substr($line,6));
				}
				$dvojbodkaPos = strpos($line,":");
				if (strpos($line,"(")===0){
					continue;
				}

				if (((($pos=strpos($line,"("))!==false && ($lastPos=strpos($line,")"))!==false) && ($dvojbodkaPos==false || $dvojbodkaPos>$pos))
					|| (($zavPos=strpos($line,"{"))!==false && strpos($line,"}")===false && strpos($line,")")===false  && $pos!==false && $zavPos>$pos) ){
					$funcName=substr($line,0,$pos);
					if ($funcName=="constructor"){
						$funcName="__construct";
					}
					if (strpos($line,"{")!==false && strpos($line,"}")===false && strpos($line,")")===false){
						$this->parseArray();
						$line = $line."callable".$this->getCurrentLine();
						$line =str_replace(["{","}"],"",$line);
						$lastPos=strpos($line,")");
					}
					$isUnableType=false;
					if (($ppos=strpos($funcName,"<"))!==false && ($llastPos=strpos($funcName,">"))!==false){
						$isUnableType=substr($funcName,$ppos+1,$ppos-$llastPos+1);
						$funcName=substr($funcName,0,$ppos);
					}
					if (in_array(strtolower($funcName),$knownMethods)){
						continue;
					}
					$knownMethods[]=strtolower($funcName);
					$funcName=trim(str_replace(["?"],[""],$funcName));
					$method = $class->addMethod($funcName);
					$method->setStatic($isStatic);
					$params = substr($line,$pos+1,$lastPos-1-$pos);
					$params=str_replace([" ","?"],"",$params);
					$params=explode(",",$params);

					foreach($currentComment as $cmt){
						$method->addComment($cmt);
						$currentComment=[];
					}
					foreach($params as $paramPos=>$param){
						if ($param==""){
							break;
						}
						$paramComment = "@param ";
						$parts = explode(":",$param);
						$param=$parts[0];
						$param=str_replace("?","",$param);
						$isMultiple=false;
						if (substr($param,0,3)=="..."){
							$param=substr($param,3);
							$isMultiple=true;
						}
						$type=null;
						if (count($parts)>1){
							$type=$parts[1];
							if (substr($type,0,strlen($isUnableType))==$isUnableType){
								$type=str_replace($isUnableType,"mixed",$type);
							}
						}
						if ($type){
							while (($pos_=strpos($type,"/*"))!==false && ($lastPos_=strpos($type,"*/"))!==false){
								$type=substr($type,0,$pos_).substr($type,$lastPos_+1);
							}
							if (strpos($type,"{")!==false){
								$type="array";
							}
							$this->checkClassNamespace($type);

							$paramComment.=$type." ";
						}
						if ($isMultiple){
							$paramComment.="...";
						}
						$paramComment.='$'.$param;
						if (isset($currentCommentParams[$paramPos])){
							$paramComment.=" ".$currentCommentParams[$paramPos];
						}
						$currentCommentParams=[];
						$method->addComment($paramComment);
						$method->addParameter($param);
					}
					$returnComment="";
					if ($currentCommentReturn){
						$returnComment = "@return ";
					}
					$return = trim(substr($line,$lastPos+1));
					if (strpos($return,":")!==false){
						$return=str_replace([":",";"," "],"",$return);
						$return=str_replace($isUnableType,"mixed",$return);

						$this->checkClassNamespace($return);

						$returnComment .= $return." ";
					}
					if ($currentCommentReturn){
						$returnComment .= $currentCommentReturn;
						$currentCommentReturn="";
					}
					if ($returnComment){
						$method->addComment($returnComment);
					}
					continue;
				}
				$line = trim(str_replace([";"," "],"",$line));
				$parts = explode(":",$line);
				$property=$parts[0];
				$propertyType=null;

				$defaultValue=NULL;
				if (count($parts)>1){
					$propertyType=$parts[1];
					if (count($parts)>2){
						$propertyType=join(":",array_slice($parts,1));
					}
					if (strpos($parts[count($parts)-1],"=>")!==false){
						$propertyType = "callable";
					}else {
						if (substr(trim($propertyType), 0, 1) == "{") {
							$propertyType="[]";
							$defaultValue = $this->parseArray();
						}
					}
				}
				if ($isInterface){
					$comment = "@property ";
					if ($propertyType) {
						$this->checkClassNamespace($propertyType);
						$comment .= $propertyType." ";
					}
					$comment.="\${$property} ";
					$comment.=join(" ",$currentComment);
					$currentComment=[];
					$class->addComment($comment);
				}else {
					if ($property=="" && $isStatic){
						$isStatic=false;
						$property="static";
					}
					$classProperty = $class->addProperty($property, $defaultValue);
					$classProperty->setStatic($isStatic);
					if (count($currentComment)) {
						foreach ($currentComment as $cmt) {
							$classProperty->addComment($cmt);
						}
						$currentComment = [];
					}
					if ($propertyType) {
						$this->checkClassNamespace($propertyType);
						$classProperty->addComment("@var " .$propertyType);
					}
				}
				continue;
			}

			$class = $class->__toString();
			echo preg_replace("/^(.*)/m",$indent."$1",$class);
			return;
		}
		
		private function parseArray(){
			$line=$this->getPreviousLine();

			$array=[];
			if (trim(str_replace("{","",substr($line,strpos($line,"{"))))!=""){
				$line = str_replace(["{","}"],"",$line);
				$lines = explode(";",$line);
				foreach($lines as $line){
					if (trim($line)==""){
						continue;
					}
					if (strpos($line,"(")!==false){
						continue;
					}
					list($key,$value) = explode(":",$line);
					$key=trim(str_replace(["?"],"",$key));
					$array[$key]=null;
				}
				return $array;
			}

			do{
				if (strpos($line,"{")===false && strpos($line,"}")!==false){
					break;
				}
				if (trim($line)==""){
					continue;
				}
				$parts = explode(":",$line);
				if (count($parts)!=2){
					continue;
				}
				list($key,$value) = $parts;
				$key=trim(str_replace(["?"],"",$key));
				$array[$key]=null;
			}while(($line = $this->getNextLine()) !== false);


			return $array;
		}

		private function parseFunction(){
			$line = $this->getPreviousLine();
			$line = str_replace(["function","declare","export"],"",$line);
			$line = trim($line);
			$indent = $this->getIndent();

			if ((($pos=strpos($line,"("))!==false && ($lastPos=strpos($line,")"))!==false)){
				$funcName=substr($line,0,$pos);
				$isUnableType=false;
				if (($ppos=strpos($funcName,"<"))!==false && ($llastPos=strpos($funcName,">"))!==false){
					$isUnableType=substr($funcName,$ppos+1,$ppos-$llastPos+1);
					$funcName=substr($funcName,0,$ppos);
				}
				$knownMethods[]=strtolower($funcName);
				$params = substr($line,$pos+1,$lastPos-1-$pos);
				$params=str_replace([" ","?"],"",$params);
				$params=explode(",",$params);

				$currentCommentParams=[];
				$currentParams=[];

				foreach($params as $paramPos=>$param){
					if ($param==""){
						break;
					}
					$paramComment = "@param ";
					$parts = explode(":",$param);
					$param=$parts[0];
					$isMultiple=false;
					if (substr($param,0,3)=="..."){
						$param=substr($param,3);
						$isMultiple=true;
					}
					$type=null;
					if (count($parts)>1){
						$type=$parts[1];
						if (substr($type,0,strlen($isUnableType))==$isUnableType){
							$type=str_replace($isUnableType,"mixed",$type);
						}
					}
					if ($type){
						$this->checkClassNamespace($type);
						$paramComment.=$type." ";
					}
					if ($isMultiple){
						$paramComment.="...";
					}
					$paramComment.='$'.$param;

					$currentCommentParams[]=$paramComment;
					$currentParams[]="\${$param}";
				}
				$returnComment="";

				$return = trim(substr($line,$lastPos+1));
				if (strpos($return,":")!==false){
					$return=str_replace([":",";"," "],"",$return);
					$return=str_replace($isUnableType,"mixed",$return);
					if (!$returnComment) {
						$returnComment = "@return ";
					}
					$this->checkClassNamespace($return);
					$returnComment .= $return;
				}

				if (count($currentCommentParams)>0 || $returnComment){
					echo "{$indent}/**".PHP_EOL;
					foreach($currentCommentParams as $comment){
						echo "{$indent} * ".$comment.PHP_EOL;
					}
					if ($returnComment){
						echo "{$indent} * ".$returnComment.PHP_EOL;
					}
					echo "{$indent} */".PHP_EOL;
				}
				$this->checkReservedKeyword($funcName);
				echo "{$indent}function {$funcName}(";
				echo join(",",$currentParams);
				echo "){}".PHP_EOL;

			}else{
				throw new UnexpectedSyntaxException($this->actualFileName,$this->lineNum+1,$line,4);
			}
		}

		private function checkClassNamespace(&$orgType) {
			$type = str_replace(".","\\",$orgType);
			$types = explode("|",$type);
			$correctedTypes=[];
			foreach($types as $type){
				$type=trim($type);
				$isArray=false;
				if (strpos($type,"\\")===0){
					$type=substr($type,1);
				}
				if (substr($type,-2)=="[]"){
					$isArray=true;
					$type=substr($type,0,-2);
				}
				if (array_key_exists(strtolower($type), $this->replaceTypes)){
					$type = $this->replaceTypes[strtolower($type)];
					goto addType;
				}
				if (in_array(strtolower($type),["number","string","boolean","void","array"])){
					goto addType;
				}
				$corrected=false;
				if (array_key_exists($this->currentNamespace,$this->knownNamespaceClasses)) {
					foreach ($this->knownNamespaceClasses[$this->currentNamespace] as $class){
						if ($type==$class){
							$corrected=true;
							break;
						}
					}
				}
				if (!$corrected){
					$type="\\".$type;
				}
				addType:
				if ($isArray){
					$type.="[]";
				}
				$correctedTypes[]=$type;
			}
			$orgType = join("|",$correctedTypes);
		}

		private function addClassNamespace($class){
			if (!array_key_exists($this->currentNamespace,$this->knownNamespaceClasses)){
				$this->knownNamespaceClasses[$this->currentNamespace]=[];
			}
			if (!in_array($class,$this->knownNamespaceClasses[$this->currentNamespace])) {
				$this->knownNamespaceClasses[$this->currentNamespace][] = $class;
			}
		}
	}
	
}

