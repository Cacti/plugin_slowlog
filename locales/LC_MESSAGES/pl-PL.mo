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
               �   *  {  �  4  B     w     z     �     �     �     �     �     �  ,   �  �  �     �     �     �  	   �     �     �     �       	   	               '     /  	   4  "   >     a     g     l     y     �     �  +   �     �     �     �     �     �  b   �     K     T     s  "   �     �  R   �                    *  !   <     ^  !   s  $   �  +   �  +   �  #        6  &   V     }     �     �     �  W   �     �       E     \  ^     �     �  	   �     �                 !   %     G     V     h     y     �     �     �     �     �  #   �  >  �     0     B     [     g     u     |  �   �  �  0   ]  �!     "#     %#     >#     G#     [#     {#     �#     �#  0   �#     6          
   D       &   S      K          E   !       [               9   ]              W   T   $               \       C   3            -   @               Z               V              %   '      +      M   L         O      B       R   )   G   7   F   ^       >   (          1                J         X      	   A       :   4   Y   Q   #            *   =           5   ,   ?   /            ;   8   U   <   H       "          .               2      I   P   0   N        %d Chars Actions Affected All Any Bytes Bytes Sent Cancel Clear Complete Create Custom Date Default Define Shifting Interval Delete Description Duration End Date End Date Selector Examined File Size upload limits in Cacti. From Go Host ID IP If the XML file containing template data is located on your local machine, select it here. Import Import MariaDB/MySQL Slowlog Import Status Import Template from Local File Imported Leave Blank for Auto Detection which runs must faster. Lines Lock Seconds Lock Time Log Entries LogFile LogFile Description MariaDB/MySQL SlowLog Details MariaDB/MySQL SlowLog File Filters MariaDB/MySQL SlowLog Query Details MariaDB/MySQL SlowLog Results - By %s Max Post Size Max Query Length Max Upload Filesize Method N/A New Slow Log No Only import the first X characters of the SQL Query from the MySQL Slow Query log. Original Query Others Please provide a description for this MySQL Slowlog file to be imported. Please provide a space delimited list of tables that you are interested in.  If you provide this list of tables the MySQL Slow Query Log will be scanned for these entries and more details statistics will be provided.  In Linux/UNIX, you may obtain a list of tables by using the following command:<br><br> print `mysql -u<i><b>user</b></i> -p<i><b>password</b></i> -e "show tables" <i><b>database</b></i> | grep -v Tables_in` | tr '\n' ' '<br><br>The values of '<i><b>user</b></i>', '<i><b>password</b></i>', and '<i><b>database</b></i>' are replaced with your values. Post-Processing Pre-Processing Queries Query Seconds Query Time Return Rows Rows Affected Rows Examined Rows Returned Rows Sent Save Search Seconds Sent Shift Time Backward Shift Time Forward Slowlog Table Names [ optional ] Slowlog needs to know the tables names used in your slowlog.  If it's the Cacti database on this system, simply check the checkbox.  Otherwise, you will have to paste the output as show below under 'Tables of Interest', or leave blank to have the Post-process detect the Tables. Start Date Start Date Selector Status String Table Name Tables Tables of Interest The initial Slowlog has been ingested.  Post Processing will take place in the background.  You can start analyzing the Details once the status is complete The maximum filesize your Apache server will allow to be uploaded is set to the value on the right.  Currently, you can not upload a file larger than this.  If you have MySQL Slow logs larger than this, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>upload_max_filesize</i></b> and increase the value.  After which you must restart Apache. The maximum size you can post to the Apache server is set to the value on the right.  If you have MySQL Slow logs larger than this value, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>post_max_size</i></b> and increase its value.  After which you must restart Apache. To Total Queries Unknown Upload Limits Use This Cacti Database User View Details Yes You must select at least one Slowlog record. Project-Id-Version: 
Report-Msgid-Bugs-To: developers@cacti.net
PO-Revision-Date: 2024-08-10 11:36-0400
Last-Translator: kaktus <kaktus_j_p@o2.pl>
Language-Team: Polish <http://translate.cacti.net/projects/cacti/flowview/pl/>
Language: pl_PL
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit
Plural-Forms: nplurals=3; plural=n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2;
X-Generator: Poedit 3.4.4
 %d Znaki Akcje Wpływ Wszystkie Dowolny Bajtów Wysłane bajty Anuluj Wyczyść Zakończone Utwórz Własny Data Domyślny Zdefiniuj interwał zmiany biegów Usuń Opis Czas trwania Data zakończenia Wybór daty końcowej Zbadane Limity przesyłania rozmiaru pliku w Cacti. Od Idź Host ID IP Jeśli plik XML zawierający dane szablonu znajduje się na komputerze lokalnym, wybierz go tutaj. Importuj Importuj MariaDB/MySQL Slowlog Status importu Importuj szablon z pliku lokalnego Zaimportowane Pozostaw pole puste dla automatycznego wykrywania, które musi działać szybciej. Linie Zablokuj sekundy Czas blokady Wpisy w dzienniku Pliki dziennika i Syslog/Eventlog Opis pliku dziennika Szczegóły MariaDB/MySQL SlowLog Filtry plików MariaDB/MySQL SlowLog Szczegóły zapytania MariaDB/MySQL SlowLog Wyniki MariaDB/MySQL SlowLog — według %s Maksymalny rozmiar wgrywanego pliku Maksymalna długość zapytania Maksymalny rozmiar przesyłanego pliku Metoda N/D Nowy dziennik spowolnień Nie Zaimportuj tylko pierwsze X znaków zapytania SQL z dziennika powolnych zapytań MySQL. Oryginalne zapytanie Inne Podaj opis tego pliku MySQL Slowlog, który ma zostać zaimportowany. Podaj rozdzielaną spacjami listę tabel, które Cię interesują.  Jeśli podasz tę listę tabel, dziennik powolnych zapytań MySQL zostanie przeskanowany pod kątem tych wpisów i zostaną podane bardziej szczegółowe statystyki.  W systemie Linux/UNIX można uzyskać listę tabel za pomocą następującego polecenia:<br><br> print 'mysql -u<i><b>użytkownik</b></i> -p<i><b>hasło</b></i> -e "pokaż tabele" <i><b>baza danych</b></i> | grep -v Tables_in' | tr '\n' ' '<br><br>Wartości "<i><b>user</b></i>", "<i><b>password</b></i>" i "<i><b>database</b></i>" są zastępowane wartościami Twoimi. PRZETWARZANIE KOŃCOWE Przetwarzanie wstępne Zapytania Sekundy zapytania Czas zapytania Powróć Wiersze Wiersze, których dotyczy problem Badane wiersze Zwrócone wiersze Wysłane wiersze Zapisz Szukaj Sekundy Wysłane Przesuń czas do tyłu Przesunięcie czasu do przodu Nazwy tabel Slowlog [ opcjonalnie ] Slowlog musi znać nazwy tabel używanych w Twoim slowlogu.  Jeśli jest to baza danych Cacti w tym systemie, po prostu zaznacz pole wyboru.  W przeciwnym razie będziesz musiał wkleić dane wyjściowe, jak pokazano poniżej, w sekcji "Tabele zainteresowań" lub pozostawić puste, aby proces końcowy wykrył tabele. Data rozpoczęcia Wybór daty rozpoczęcia Ciąg stanu Nazwa tablicy Tabele Tabele zainteresowań Początkowy Slowlog został pojęty.  Przetwarzanie końcowe będzie odbywać się w tle.  Możesz rozpocząć analizę szczegółów po zakończeniu statusu Maksymalny rozmiar pliku, który serwer Apache pozwoli przesłać, jest ustawiony na wartość po prawej stronie.  Obecnie nie można przesłać pliku większego niż ten.  Jeśli masz dzienniki MySQL Slow większe niż ten, musisz zmienić plik <i>php.ini</i> powiązany z Apache, znaleźć zmienną <b><i>upload_max_filesize</i></b> i zwiększyć wartość.  Po czym musisz ponownie uruchomić Apache. Maksymalny rozmiar, jaki można publikować na serwerze Apache, jest ustawiony na wartość po prawej stronie.  Jeśli masz dzienniki MySQL Slow większe niż ta wartość, musisz zmienić plik <i>php.ini</i> powiązany z Apache, znaleźć zmienną <b><i>post_max_size</i></b> i zwiększyć jej wartość.  Po czym musisz ponownie uruchomić Apache. Do Łączna liczba zapytań Nieznany Limity przesyłania Użyj tej bazy danych kaktusów Użytkownik Zobacz szczegóły Tak Musisz wybrać co najmniej jeden rekord Slowlog. 