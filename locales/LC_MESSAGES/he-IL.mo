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
               �   *  {  �  4  B     w     z     �     �     �     �     �     �  ,   �  `  �     Z     c     p          �     �     �  
   �     �  
   �     �     �  
   �     �     	     &  
   -     8     N  '   b     �  9   �     �     �     �     �     �  u   �  
   t           �  +   �     �  ]   �  
   E     P     f     x     �     �  "   �  +   �  +   	  .   5      d     �  +   �     �     �     �        �        �     �  K   �  �  �     �     �     �     �       !   (  
   J     U     o     �     �     �  
   �  
   �     �     �     �  4     �  P     �      !     ,!     @!     P!     Y!  �   q!  �  "  �  �#     g%     l%     �%     �%  9   �%  
   �%     �%      &  9   &     6          
   D       &   S      K          E   !       [               9   ]              W   T   $               \       C   3            -   @               Z               V              %   '      +      M   L         O      B       R   )   G   7   F   ^       >   (          1                J         X      	   A       :   4   Y   Q   #            *   =           5   ,   ?   /            ;   8   U   <   H       "          .               2      I   P   0   N        %d Chars Actions Affected All Any Bytes Bytes Sent Cancel Clear Complete Create Custom Date Default Define Shifting Interval Delete Description Duration End Date End Date Selector Examined File Size upload limits in Cacti. From Go Host ID IP If the XML file containing template data is located on your local machine, select it here. Import Import MariaDB/MySQL Slowlog Import Status Import Template from Local File Imported Leave Blank for Auto Detection which runs must faster. Lines Lock Seconds Lock Time Log Entries LogFile LogFile Description MariaDB/MySQL SlowLog Details MariaDB/MySQL SlowLog File Filters MariaDB/MySQL SlowLog Query Details MariaDB/MySQL SlowLog Results - By %s Max Post Size Max Query Length Max Upload Filesize Method N/A New Slow Log No Only import the first X characters of the SQL Query from the MySQL Slow Query log. Original Query Others Please provide a description for this MySQL Slowlog file to be imported. Please provide a space delimited list of tables that you are interested in.  If you provide this list of tables the MySQL Slow Query Log will be scanned for these entries and more details statistics will be provided.  In Linux/UNIX, you may obtain a list of tables by using the following command:<br><br> print `mysql -u<i><b>user</b></i> -p<i><b>password</b></i> -e "show tables" <i><b>database</b></i> | grep -v Tables_in` | tr '\n' ' '<br><br>The values of '<i><b>user</b></i>', '<i><b>password</b></i>', and '<i><b>database</b></i>' are replaced with your values. Post-Processing Pre-Processing Queries Query Seconds Query Time Return Rows Rows Affected Rows Examined Rows Returned Rows Sent Save Search Seconds Sent Shift Time Backward Shift Time Forward Slowlog Table Names [ optional ] Slowlog needs to know the tables names used in your slowlog.  If it's the Cacti database on this system, simply check the checkbox.  Otherwise, you will have to paste the output as show below under 'Tables of Interest', or leave blank to have the Post-process detect the Tables. Start Date Start Date Selector Status String Table Name Tables Tables of Interest The initial Slowlog has been ingested.  Post Processing will take place in the background.  You can start analyzing the Details once the status is complete The maximum filesize your Apache server will allow to be uploaded is set to the value on the right.  Currently, you can not upload a file larger than this.  If you have MySQL Slow logs larger than this, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>upload_max_filesize</i></b> and increase the value.  After which you must restart Apache. The maximum size you can post to the Apache server is set to the value on the right.  If you have MySQL Slow logs larger than this value, you must alter the <i>php.ini</i> file associated with Apache, find the variable <b><i>post_max_size</i></b> and increase its value.  After which you must restart Apache. To Total Queries Unknown Upload Limits Use This Cacti Database User View Details Yes You must select at least one Slowlog record. Project-Id-Version: 
Report-Msgid-Bugs-To: developers@cacti.net
PO-Revision-Date: 2024-08-10 11:32-0400
Last-Translator: 
Language-Team: 
Language: he_IL
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit
Plural-Forms: nplurals=4; plural=(n==1 ? 0 : n==2 ? 1 : n>10 && n%10==0 ? 2 : 3);
X-Generator: Poedit 3.4.4
 %d Chars פעולות המושפעת הכל הכל בייטים בתים שנשלחו ביטול נקה הושלם צור מותאם אישית תאריך ברירת מחדל הגדר מרווח מעבר מחק תיאור תקופת השכרה תאריך סיום סיים את בורר התאריכים בדק מגבלות העלאה של גודל קובץ ב- Cacti. מ עבור שרת מארח מזהה כתובת IP אם קובץ XML המכיל נתוני תבנית ממוקם במחשב המקומי שלך, בחר אותו כאן. ייבוא ייבוא MariaDB/MySQL Slowlog מצב ייבוא ייבוא תבנית מקובץ מקומי יובא השאר ריק לזיהוי אוטומטי שפועל חייב להיות מהיר יותר. שורות נעילת שניות זמן נעילה ערכי יומן רישום קובץ יומן תיאור LogFile MariaDB/MySQL SlowLog - פרטים מסנני קבצים MariaDB/MySQL SlowLog פרטי שאילתת MariaDB/MySQL SlowLog MariaDB/MySQL תוצאות SlowLog - לפי %s גודל פוסט מקסימלי אורך שאילתה מרבי גודל קובץ העלאה מקסימלי שיטה לא זמין יומן איטי חדש לא יבא רק את תווי X התווים הראשונים של שאילתת SQL מיומן השאילתה האיטית של MySQL. שאילתה מקורית כללי אנא ספק תיאור עבור קובץ MySQL Slowlog זה לייבוא. אנא ספקו רשימה מופרדת של טבלאות שאתם מעוניינים בהן.  אם תספק רשימה זו של טבלאות, יומן השאילתות האיטי של MySQL ייסרק עבור ערכים אלה ונתונים סטטיסטיים נוספים יסופקו.  ב- Linux/UNIX, ניתן לקבל רשימת טבלאות באמצעות הפקודה הבאה:<br><br> הדפס 'MySQL -U<i><b>משתמש</b></i> -p<i><b>סיסמה</b></i> -e "הצג טבלאות" <i><b>מסד נתונים</b></i> | גרפ -V Tables_in' | tr '\n' ' '<br><br>הערכים של '<i><b>משתמש</b></i>', '<i><b>סיסמה</b></i>' <i><b>ו'מסד נתונים</b></i>' מוחלפים בערכים שלך. לאחר עיבוד עיבוד מקדים שאילתות שניות שאילתה זמן שאילתה חזרה אל עריכת פוסט שורות שורות מושפעות שורות שנבדקו שורות שהוחזרו שורות שנשלחו שמור חיפוש שניות נשלח משמרת זמן לאחור משמרת זמן קדימה שמות טבלאות Slowlog [ אופציונלי ] Slowlog צריך לדעת את שמות הטבלאות המשמשות ביומן האיטי.  אם זהו מסד הנתונים של קקטוסים במערכת זו, פשוט סמן את תיבת הסימון.  אחרת, יהיה עליך להדביק את הפלט כפי שמוצג להלן תחת 'טבלאות עניין', או להשאיר ריק כדי שהתהליך שלאחר מכן יזהה את הטבלאות. תאריך התחלה בורר תאריך התחלה מחרוזת מצב שם הטבלה טבלה טבלאות עניין ה-Slowlog הראשוני נבלע.  עיבוד פוסט יתבצע ברקע.  תוכל להתחיל לנתח את הפרטים לאחר השלמת המצב גודל הקובץ המרבי ששרת Apache יאפשר להעלות מוגדר לערך מימין.  נכון לעכשיו, לא ניתן להעלות קובץ גדול מזה.  אם יש לך יומני MySQL Slow גדולים מזה, עליך לשנות את קובץ <i>php.ini</i> המשויך ל- Apache, למצוא את <b><i>upload_max_filesize</i></b> המשתנה ולהגדיל את הערך.  לאחר מכן עליך להפעיל מחדש את אפאצ'י. הגודל המרבי שניתן להציב בשרת Apache מוגדר לערך מימין.  אם יש לך יומני MySQL Slow גדולים מערך זה, עליך לשנות את קובץ <i>php.ini</i> המשויך ל- Apache, למצוא את <b><i>המשתנה post_max_size</i></b> ולהגדיל את ערכו.  לאחר מכן עליך להפעיל מחדש את אפאצ'י. אל סה"כ שאילתות לא ידוע מגבלות העלאה השתמש במסד נתונים זה של קקטוסים משתמש הצג פרטים כן עליך לבחור לפחות רשומת Slowlog אחת. 