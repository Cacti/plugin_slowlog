��    ^           �      �     �          
                 
   !     ,     3     9     B     I     P     U     ]     v     }     �     �     �     �  !   �     �     �     �     �     �  Z   �     F	     M	     j	     x	     �	  6   �	     �	     �	  	   �	     �	     
     	
     
  "   ;
  #   ^
  %   �
     �
     �
     �
     �
     �
     �
     �
  R   �
     I     X  H   _  7  �     �     �     �       
              '     ,     :     H  	   V     `     e     l     t     y     �      �    �  
   �     �     �  
               �   *  {  �  4  B     w     z     �     �     �     �     �     �  ,   �  �  �     �     �  	   �     �     �     �     �     �     �     �     �  	   �     �  	         
     *     6     C  	   H     R  
   e  1   p     �     �     �     �     �  c   �  
         #     D  +   T     �  H   �     �     �     �  
             -     F  %   d  '   �  *   �     �     �          0     8     <     P  V   T     �     �  V   �  j  '     �     �     �     �     �     �     �     �     �                0     8     ?  	   H     R     k      �  =  �  
   �     �        	             !  �   7  �  �  j  �!      #     #     !#     *#     :#  	   Y#     c#     r#  2   u#     6          
   D       &   S      K          E   !       [               9   ]              W   T   $               \       C   3            -   @               Z               V              %   '      +      M   L         O      B       R   )   G   7   F   ^       >   (          1                J         X      	   A       :   4   Y   Q   #            *   =           5   ,   ?   /            ;   8   U   <   H       "          .               2      I   P   0   N        %d Chars Actions Affected All Any Bytes Bytes Sent Cancel Clear Complete Create Custom Date Default Define Shifting Interval Delete Description Duration End Date End Date Selector Examined File Size upload limits in Cacti. From Go Host ID IP If the XML file containing template data is located on your local machine, select it here. Import Import MariaDB/MySQL Slowlog Import Status Import Template from Local File Imported Leave Blank for Auto Detection which runs must faster. Lines Lock Seconds Lock Time Log Entries LogFile LogFile Description MariaDB/MySQL SlowLog Details MariaDB/MySQL SlowLog File Filters MariaDB/MySQL SlowLog Query Details MariaDB/MySQL SlowLog Results - By %s Max Post Size Max Query Length Max Upload Filesize Method N/A New Slow Log No Only import the first X characters of the SQL Query from the MySQL Slow Query log. Original Query Others Please provide a description for this MySQL Slowlog file to be imported. Please provide a space delimited list of tables that you are interested in.  If you provide this list of tables the MySQL Slow Query Log will be scanned for these entries and more details statistics will be provided.  In Linux/UNIX, you may obtain a list of tables by using the following command:<br><br> print `mysql -u<i><b>user</b></i> -p<i><b>password</b></i> -e "show tables" <i><b>database</b></i> | grep -v Tables_in` | tr '\n' ' '<br><br>The values of '<i><b>user</b></i>', '<i><b>password</b></i>', and '<i><b>database</b></i>' are replaced with your values. Post-Processing Pre-Processing Queries Query Seconds Query Time Return Rows Rows Affected Rows Examined Rows Returned Rows Sent Save Search Seconds Sent Shift Time Backward Shift Time Forward Slowlog Table Names [ optional ] Slowlog needs to know the tables names used in your slowlog.  If it's the Cacti database on this system, simply check the checkbox.  Otherwise, you will have to paste the output as show below under 'Tables of Interest', or leave blank to have the Post-process detect the Tables. Start Date Start Date Selector Status String Table Name Tables Tables of Interest The initial Slowlog has been ingested.  Post Processing will take place in the background.  You can start analyzing the Details once the status is complete The maximum filesize your Apache server will allow to be uploaded is set to the value on the right.  Currently, you can not upload a file larger than this.  If you have MySQL Slow logs larger than this, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>upload_max_filesize</i></b> and increase the value.  After which you must restart Apache. The maximum size you can post to the Apache server is set to the value on the right.  If you have MySQL Slow logs larger than this value, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>post_max_size</i></b> and increase its value.  After which you must restart Apache. To Total Queries Unknown Upload Limits Use This Cacti Database User View Details Yes You must select at least one Slowlog record. Project-Id-Version: 
Report-Msgid-Bugs-To: developers@cacti.net
PO-Revision-Date: 2024-08-10 11:35-0400
Last-Translator: Edwin Lankamp <edwin.lankamp@gmail.com>
Language-Team: Dutch <http://translate.cacti.net/projects/cacti/flowview/nl/>
Language: nl_NL
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit
Plural-Forms: nplurals=2; plural=n != 1;
X-Generator: Poedit 3.4.4
 %d Chars Acties Aangedaan Alle Alle Bytes Verzonden bytes Annuleer Herstel Voltooid Maken Aangepast Datum Standaard Definieer verschuivingsinterval Verwijderen Omschrijving Duur Einddatum Einddatum selector Onderzocht Uploadlimieten voor bestandsgrootte in cactussen. Van Ga Host ID IP Als het XML-bestand met sjabloongegevens zich op uw lokale computer bevindt, selecteert u het hier. Importeren MariaDB/MySQL Slowlog importeren Importeerstatus Importeer template vanaf een lokaal bestand Geïmporteerd Laat leeg voor automatische detectie die sneller moet worden uitgevoerd. Regels Seconden vergrendelen Vergrendel tijd Log regels Logbestand en syslog/eventlog Beschrijving van LogFile MariaDB/MySQL SlowLog Details MariaDB/MySQL SlowLog-bestandsfilters Details van MariaDB/MySQL SlowLog-query MariaDB/MySQL SlowLog Resultaten - Door %s Maximale berichtgrootte Maximale lengte van query's Maximale uploadbestandsgrootte Methode N/B Nieuw traag logboek Nee Importeer alleen de eerste X tekens van de SQL Query uit het MySQL Slow Query-logboek. Oorspronkelijke zoekopdracht Anderen Geef een beschrijving op voor dit MySQL Slowlog-bestand dat moet worden geïmporteerd. Geef een door ruimte gescheiden lijst van tabellen waarin u geïnteresseerd bent.  Als u deze lijst met tabellen verstrekt, wordt het MySQL Slow Query Log gescand op deze vermeldingen en worden er meer gedetailleerde statistieken verstrekt.  In Linux/UNIX kunt u een lijst met tabellen verkrijgen met behulp van de volgende opdracht:<br><br> Print 'mySQL -u<i><b>gebruiker</b></i> -p<i><b>wachtwoord</b></i> -e "Toon tabellen" <i><b>database</b></i> | Grep -v Tables_in' | tr '\n' ' '<br><br>De waarden '<i><b>gebruiker</b></i>', '<i><b>wachtwoord</b></i>' en '<i><b>database</b></i>' worden vervangen door uw waarden. Post-processing Voorbewerking Zoekopdrachten Seconden opvragen Tijd opvragen Terug Rijen Geraakte rijen Rijen onderzocht Geretourneerde rijen Verzonden rijen Opslaan Zoeken Seconden Verzonden Verschuif tijd achteruit Verschuif tijd vooruit Slowlog-tabelnamen [ optioneel ] Slowlog moet de tabelnamen kennen die in uw slowlog worden gebruikt.  Als het de cactussendatabase op dit systeem is, vink dan gewoon het selectievakje aan.  Anders moet u de uitvoer plakken zoals hieronder weergegeven onder 'Interessante tabellen', of leeg laten om de tabellen te laten detecteren door het naproces. Startdatum Startdatum selector Status String Tabelnaam Tabellen Interessante tabellen De eerste Slowlog is ingenomen.  De nabewerking vindt op de achtergrond plaats.  U kunt beginnen met het analyseren van de details zodra de status is voltooid De maximale bestandsgrootte die uw Apache-server toestaat om te worden geüpload, is ingesteld op de waarde aan de rechterkant.  Op dit moment kunt u geen bestand uploaden dat groter is dan dit.  Als je MySQL Slow-logboeken hebt die groter zijn dan dit, moet je het <i>php.ini-bestand</i> dat aan Apache is gekoppeld, wijzigen, de variabele <b><i>upload_max_filesize</i></b> vinden en de waarde verhogen.  Waarna je Apache opnieuw moet opstarten. De maximale grootte die u op de Apache-server kunt posten, is ingesteld op de waarde aan de rechterkant.  Als u MySQL Slow-logboeken hebt die groter zijn dan deze waarde, moet u het <i>php.ini-bestand</i> dat aan Apache is gekoppeld, wijzigen, de variabele <b><i>post_max_size</i></b> vinden en de waarde ervan verhogen.  Waarna je Apache opnieuw moet opstarten. Tot Totaal aantal zoekopdrachten Onbekend Upload limieten Gebruik deze cactussendatabase Gebruiker Bekijk details Ja U moet ten minste één Slowlog-record selecteren. 