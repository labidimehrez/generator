<?php
/**
 * Générateur d'entités Symfony 5 avec attributs protégés
 * 
 * Ce script génère des entités Symfony à partir d'une base de données MySQL
 * avec des attributs protégés qui ont exactement les mêmes noms que les colonnes.
 * 
 * Usage:
 * php generate-entities.php host dbname username password [output_dir]
 */

// Vérifier les arguments
if ($argc < 5) {
    echo "Usage: php generate-entities.php host dbname username password [output_dir]\n";
    exit(1);
}

// Récupérer les arguments
$host = $argv[1];
$dbName = $argv[2];
$username = $argv[3];
$password = $argv[4];
$outputDir = isset($argv[5]) ? $argv[5] : __DIR__ . '/Entity';

// Créer le répertoire de sortie s'il n'existe pas
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Connexion à la base de données
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbName", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connexion à la base de données réussie.\n";
} catch (PDOException $e) {
    echo "Erreur de connexion: " . $e->getMessage() . "\n";
    exit(1);
}

// Récupérer toutes les tables
$tables = [];
$stmt = $pdo->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

echo "Tables trouvées: " . count($tables) . "\n";

// Pour chaque table
foreach ($tables as $table) {
    echo "Traitement de la table: $table\n";
    
    // Obtenir les informations sur les colonnes
    $columns = [];
    $stmt = $pdo->query("DESCRIBE $table");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row;
    }
    
    // Obtenir les clés étrangères
    $foreignKeys = [];
    $stmt = $pdo->query("
        SELECT 
            TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM
            information_schema.KEY_COLUMN_USAGE
        WHERE
            REFERENCED_TABLE_SCHEMA = '$dbName'
            AND TABLE_NAME = '$table'
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $foreignKeys[$row['COLUMN_NAME']] = [
            'referencedTable' => $row['REFERENCED_TABLE_NAME'],
            'referencedColumn' => $row['REFERENCED_COLUMN_NAME']
        ];
    }
    
    // Générer le nom de la classe
    $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $table)));
    
    // Générer le contenu du fichier
    $content = "<?php\n\n";
    $content .= "namespace App\\Entity;\n\n";
    $content .= "use Doctrine\\ORM\\Mapping as ORM;\n";
    
    // Ajouter les imports pour les relations
    $hasRelations = false;
    foreach ($foreignKeys as $fk) {
        $hasRelations = true;
        break;
    }
    
    if ($hasRelations) {
        $content .= "use Doctrine\\Common\\Collections\\ArrayCollection;\n";
        $content .= "use Doctrine\\Common\\Collections\\Collection;\n";
    }
    
    $content .= "\n/**\n";
    $content .= " * @ORM\\Entity(repositoryClass=\"App\\Repository\\{$className}Repository\")\n";
    $content .= " * @ORM\\Table(name=\"$table\", schema=\"$dbName\")\n";
    $content .= " */\n";
    $content .= "class $className\n";
    $content .= "{\n";
    
    // Propriétés - On utilise exactement le même nom que la colonne
    foreach ($columns as $column) {
        $columnName = $column['Field'];
        
        $content .= "    /**\n";
        
        // Déterminer le type
        $type = mapDatabaseTypeToPhpType($column['Type']);
        
        // Générer les annotations ORM
        if ($column['Key'] === 'PRI') {
            $content .= "     * @ORM\\Id\n";
            $content .= "     * @ORM\\Column(type=\"$type\", name=\"$columnName\")\n";
            
            if (strpos($column['Extra'], 'auto_increment') !== false) {
                $content .= "     * @ORM\\GeneratedValue(strategy=\"AUTO\")\n";
            }
        } elseif (isset($foreignKeys[$columnName])) {
            $refTable = $foreignKeys[$columnName]['referencedTable'];
            $refClass = str_replace(' ', '', ucwords(str_replace('_', ' ', $refTable)));
            $content .= "     * @ORM\\ManyToOne(targetEntity=\"App\\Entity\\$refClass\")\n";
            $content .= "     * @ORM\\JoinColumn(name=\"$columnName\", referencedColumnName=\"{$foreignKeys[$columnName]['referencedColumn']}\")\n";
        } else {
            $nullable = $column['Null'] === 'YES' ? ', nullable=true' : '';
            $content .= "     * @ORM\\Column(type=\"$type\", name=\"$columnName\"$nullable)\n";
        }
        
        $content .= "     */\n";
        
        // Propriété protégée avec exactement le même nom que la colonne
        $content .= "    protected \$$columnName;\n\n";
    }
    
    // Constructeur
    $content .= "    public function __construct()\n";
    $content .= "    {\n";
    
    if ($hasRelations) {
        $content .= "        // Initialiser les collections pour les relations\n";
        foreach ($foreignKeys as $key => $fk) {
            $refTable = $fk['referencedTable'];
            $content .= "        \$this->{$refTable}_collection = new ArrayCollection();\n";
        }
    }
    
    $content .= "    }\n\n";
    
    // Ajouter les méthodes magiques __get et __set
    $content .= "    /**\n";
    $content .= "     * Accesseur magique pour les propriétés\n";
    $content .= "     * \n";
    $content .= "     * @param string \$property\n";
    $content .= "     * @return mixed\n";
    $content .= "     */\n";
    $content .= "    public function __get(\$property)\n";
    $content .= "    {\n";
    $content .= "        return \$this->\$property;\n";
    $content .= "    }\n\n";
    
    $content .= "    /**\n";
    $content .= "     * Mutateur magique pour les propriétés\n";
    $content .= "     * \n";
    $content .= "     * @param string \$property\n";
    $content .= "     * @param mixed \$value\n";
    $content .= "     * @return \$this\n";
    $content .= "     */\n";
    $content .= "    public function __set(\$property, \$value)\n";
    $content .= "    {\n";
    $content .= "        \$this->\$property = \$value;\n";
    $content .= "        return \$this;\n";
    $content .= "    }\n\n";
    
    // Pour Doctrine, on doit également inclure certaines méthodes standard pour les ID
    foreach ($columns as $column) {
        if ($column['Key'] === 'PRI') {
            $columnName = $column['Field'];
            $methodName = ucfirst(toCamelCase($columnName));
            
            // Méthode getId explicite (nécessaire pour Doctrine)
            $content .= "    /**\n";
            $content .= "     * Méthode getId requise par Doctrine\n";
            $content .= "     * \n";
            $content .= "     * @return mixed\n";
            $content .= "     */\n";
            $content .= "    public function getId()\n";
            $content .= "    {\n";
            $content .= "        return \$this->$columnName;\n";
            $content .= "    }\n\n";
            
            break;
        }
    }
    
    $content .= "}\n";
    
    // Écrire le fichier
    file_put_contents("$outputDir/$className.php", $content);
    echo "Entité $className générée.\n";
}

echo "Terminé. Toutes les entités ont été générées dans $outputDir\n";

/**
 * Convertit un nom de colonne en camelCase
 */
function toCamelCase($string) {
    $string = str_replace('_', ' ', $string);
    $string = ucwords($string);
    $string = str_replace(' ', '', $string);
    return lcfirst($string);
}

/**
 * Mappe un type MySQL vers un type Doctrine
 */
function mapDatabaseTypeToPhpType($dbType) {
    if (strpos($dbType, 'int') !== false) {
        return 'integer';
    } elseif (strpos($dbType, 'float') !== false || strpos($dbType, 'double') !== false || strpos($dbType, 'decimal') !== false) {
        return 'float';
    } elseif (strpos($dbType, 'datetime') !== false) {
        return 'datetime';
    } elseif (strpos($dbType, 'date') !== false) {
        return 'date';
    } elseif (strpos($dbType, 'time') !== false) {
        return 'time';
    } elseif (strpos($dbType, 'text') !== false || strpos($dbType, 'char') !== false || strpos($dbType, 'varchar') !== false) {
        return 'string';
    } elseif (strpos($dbType, 'blob') !== false) {
        return 'blob';
    } elseif (strpos($dbType, 'bool') !== false) {
        return 'boolean';
    } else {
        return 'string';
    }
}

/**
 * Mappe un type Doctrine vers un type PHP pour PHPDoc
 */
function mapToPhpDocType($type) {
    switch ($type) {
        case 'integer':
            return 'int';
        case 'float':
            return 'float';
        case 'datetime':
        case 'date':
        case 'time':
            return '\\DateTime';
        case 'string':
            return 'string';
        case 'boolean':
            return 'bool';
        case 'array':
            return 'array';
        default:
            return 'mixed';
    }
}
