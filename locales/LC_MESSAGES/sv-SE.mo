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
               �   *  {  �  4  B     w     z     �     �     �     �     �     �  ,   �  9  �  	   3  
   =     H     P     U     f     k     y     �  
   �     �     �     �     �     �     �     �     �  	   �     �  
     -        A     G     M     S     V  P   Y  	   �     �     �     �  
     =        J     Q     _     t     �     �  $   �  %   �  +   �  &        A     U  #   j     �     �     �     �  R   �             G     \  a     �     �     �     �     �                    /     A     S     b     h     m     v     ~     �  "   �  0  �  
   �     �       
   !     ,     5  �   J  �  �  Y  �      �!     �!     �!     �!     "  
   5"     @"     N"  '   Q"     6          
   D       &   S      K          E   !       [               9   ]              W   T   $               \       C   3            -   @               Z               V              %   '      +      M   L         O      B       R   )   G   7   F   ^       >   (          1                J         X      	   A       :   4   Y   Q   #            *   =           5   ,   ?   /            ;   8   U   <   H       "          .               2      I   P   0   N        %d Chars Actions Affected All Any Bytes Bytes Sent Cancel Clear Complete Create Custom Date Default Define Shifting Interval Delete Description Duration End Date End Date Selector Examined File Size upload limits in Cacti. From Go Host ID IP If the XML file containing template data is located on your local machine, select it here. Import Import MariaDB/MySQL Slowlog Import Status Import Template from Local File Imported Leave Blank for Auto Detection which runs must faster. Lines Lock Seconds Lock Time Log Entries LogFile LogFile Description MariaDB/MySQL SlowLog Details MariaDB/MySQL SlowLog File Filters MariaDB/MySQL SlowLog Query Details MariaDB/MySQL SlowLog Results - By %s Max Post Size Max Query Length Max Upload Filesize Method N/A New Slow Log No Only import the first X characters of the SQL Query from the MySQL Slow Query log. Original Query Others Please provide a description for this MySQL Slowlog file to be imported. Please provide a space delimited list of tables that you are interested in.  If you provide this list of tables the MySQL Slow Query Log will be scanned for these entries and more details statistics will be provided.  In Linux/UNIX, you may obtain a list of tables by using the following command:<br><br> print `mysql -u<i><b>user</b></i> -p<i><b>password</b></i> -e "show tables" <i><b>database</b></i> | grep -v Tables_in` | tr '\n' ' '<br><br>The values of '<i><b>user</b></i>', '<i><b>password</b></i>', and '<i><b>database</b></i>' are replaced with your values. Post-Processing Pre-Processing Queries Query Seconds Query Time Return Rows Rows Affected Rows Examined Rows Returned Rows Sent Save Search Seconds Sent Shift Time Backward Shift Time Forward Slowlog Table Names [ optional ] Slowlog needs to know the tables names used in your slowlog.  If it's the Cacti database on this system, simply check the checkbox.  Otherwise, you will have to paste the output as show below under 'Tables of Interest', or leave blank to have the Post-process detect the Tables. Start Date Start Date Selector Status String Table Name Tables Tables of Interest The initial Slowlog has been ingested.  Post Processing will take place in the background.  You can start analyzing the Details once the status is complete The maximum filesize your Apache server will allow to be uploaded is set to the value on the right.  Currently, you can not upload a file larger than this.  If you have MySQL Slow logs larger than this, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>upload_max_filesize</i></b> and increase the value.  After which you must restart Apache. The maximum size you can post to the Apache server is set to the value on the right.  If you have MySQL Slow logs larger than this value, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>post_max_size</i></b> and increase its value.  After which you must restart Apache. To Total Queries Unknown Upload Limits Use This Cacti Database User View Details Yes You must select at least one Slowlog record. Project-Id-Version: 
Report-Msgid-Bugs-To: developers@cacti.net
PO-Revision-Date: 2024-08-10 11:38-0400
Last-Translator: 
Language-Team: 
Language: sv_SE
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit
Plural-Forms: nplurals=2; plural=(n != 1);
X-Generator: Poedit 3.4.4
 %d Tecken Åtgärder Drabbad Alla Vilken som helst Byte Skickade byte Avbryt Rensa Genomförd Skapa Anpassad Datum Standard Definiera växlingsintervall Radera Beskrivning Varaktighet Slutdatum Slutdatum Väljare Undersökt Uppladdningsgränser för filstorlek i Cacti. Från Start Värd ID IP Om XML-filen som innehåller mallen finns på din lokala maskin, välj den här. Importera Importera MariaDB/MySQL Slowlog Import Status Importera mall från lokal fil Importerad Lämna tomt för Automatisk identifiering som körs snabbare. Linjer Lås sekunder Tid för utelåsning App loggnoteringar Loggfil LogFile Beskrivning Information om MariaDB/MySQL SlowLog Filter för MariaDB/MySQL SlowLog-fil Information om MariaDB/MySQL SlowLog-fråga MariaDB/MySQL SlowLog Resultat - av %s Max inläggsstorlek Maximal frågelängd Maximal filstorlek för uppladdning Metod N/A Ny långsam logg Nej Importera endast de första X tecknen i SQL-frågan från MySQL Slow Query-loggen. Ursprunglig fråga Andra Ange en beskrivning av den här MySQL Slowlog-filen som ska importeras. Ange en blankstegsavgränsad lista över tabeller som du är intresserad av.  Om du anger den här listan med tabeller kommer MySQL Slow Query Log att skannas efter dessa poster och mer detaljerad statistik kommer att tillhandahållas.  I Linux/UNIX kan du hämta en lista över tabeller med hjälp av följande kommando:<br><br> Skriv ut 'mysql -u<i><b>användare</b></i> -p<i><b>lösenord</b></i> -e "visa tabeller" <i><b>databas</b></i> | grep -v Tables_in" | tr '\n' ' '<br><br>Värdena för "<i><b>användare</b></i>", "<i><b>lösenord</b></i>" och "<i><b>databas</b></i>" ersätts med dina värden. Efterbehandling Förbehandling Databasfrågor Fråga sekunder Tid för fråga Tillbaka Rader Rader som påverkas Undersökta rader Returnerade rader Skickade rader Spara Sök Sekunder Skickat Skifttid bakåt Skifttid framåt Tabellnamn för slowlog [valfritt] Slowlog behöver känna till tabellnamnen som används i din slowlog.  Om det är Cacti-databasen på det här systemet markerar du helt enkelt kryssrutan.  Annars måste du klistra in utdata som visas nedan under "Tabeller av intresse" eller lämna tomt för att efterprocessen ska upptäcka tabellerna. Startdatum Startdatumsväljare Status sträng Tabellnamn Tabeller Tabeller av intresse Den första långsamma loggen har matats in.  Efterbearbetningen kommer att ske i bakgrunden.  Du kan börja analysera detaljerna när statusen är klar Den maximala filstorleken som din Apache-server tillåter att laddas upp är inställd på värdet till höger.  För närvarande kan du inte ladda upp en fil som är större än så.  Om du har MySQL Slow-loggar som är större än så måste du ändra den <i>php.ini</i> filen som är associerad med Apache, hitta variabeln <b><i>upload_max_filesize</i></b> och öka värdet.  Därefter måste du starta om Apache. Den maximala storleken du kan skicka till Apache-servern är inställd på värdet till höger.  Om du har långsamma MySQL-loggar som är större än det här värdet måste du ändra den <i>php.ini</i> filen som är associerad med Apache, hitta variabeln <b><i>post_max_size</i></b> och öka dess värde.  Därefter måste du starta om Apache. Till Totalt antal frågor Okänd Gränser för uppladdning Använd denna kaktusdatabas Användare Visa detaljer Ja Du måste välja minst en Slowlog-post. 