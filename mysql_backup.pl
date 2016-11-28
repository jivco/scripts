#!/usr/bin/perl
####
#       Author:         Anton Antonov  <aantonov@neterra.net>
#       Author:         Zhivko Todorov <ztodorov@neterra.net> - add feature to pass custom arguments and code cleanup
#       Date:           28-Nov-2016
#       Version:        1.3.4
#       License:        GPL
#
# Changelog: 28-Nov-2016 1.3.4 - added --single-transaction parameter for mysqldump
# Changelog: 13-Jul-2016 1.3.3 - added single quotes around mysql password
####

use strict;
use Net::FTP;
use DBI;
use Sys::Hostname;
use Getopt::Long;

MAIN:
{

########## EDIT HERE ##################

    my $tmp_path = "/var/tmp/";

    my $ftp_host = "127.0.0.1";
    my $ftp_user = "backupuser";
    my $ftp_pass = "password";

    my $mysql_host = "127.0.0.1";
    my $mysql_port = 3306;
    my $mysql_user = "root";
    my $mysql_pass = 'password';

    my $keep_old_files       = 604800;
    my $compress             = "/usr/bin/pbzip2";
    my $full_bkp_name_prefix = "fullSQL";
    my $db_name_prefix = "DB";

########## EDIT HERE ##################

    my $server_params;

    if ($mysql_pass) {
        $server_params = " -h $mysql_host -P $mysql_port -u $mysql_user -p'$mysql_pass' ";
    }
    else {
        $server_params = " -h $mysql_host -P $mysql_port -u $mysql_user ";
    }

    my $stopslave      = ''; # from cli
    my $allDBinOneFile = ''; # from cli
    my $help           = undef(); # Ask for usage info.

    my $tmp_file;
    my $ftp_file;
    my $cmd_sql;
    my $comment_output;

    my %user_option;

    my @databases;
    my @databases_to_backup;

    my $time     = time();
    my $hostname =  hostname;
    my $ftp_dir = $hostname."/";

    # Check path
    if ( !-d "$tmp_path" ) {
        print STDERR "not found $tmp_path\n";
        exit(1);
    }

    # Check compressor
    if ( !-f "$compress" ) {
        print STDERR "not found $compress\n";
        exit(1);
    }

    # Receive command line parameters.
    GetOptions(
        "stopslave=s"       => \$stopslave,
        "alldbinonefile=s"  => \$allDBinOneFile,
        "help"              => \$help
    );

    # Show usage info.
    if ($help) {
        showHelp();
        exit(0);
    }

    # Parse command line arguments.
    %user_option = parseCLIArgs( $stopslave, $allDBinOneFile ) ;


    # connect to mysql server
    my $dbh = DBI->connect( "DBI:mysql:mysql;host=$mysql_host;port=$mysql_port", $mysql_user, $mysql_pass, { RaiseError => 1 } ) or
die( "Couldn't connect to database: " . DBI->errstr );

    # if allDBinOneFile

    if ($user_option{"allDBinOneFile"}) {

        $ftp_file = "$hostname-"."$full_bkp_name_prefix" . ".$time" . ".sql.bz2";
        $tmp_file = "$tmp_path" . "$ftp_file";

        # stop slave
        if ($user_option{"stopslave"}) {

            # Stopping SQL slave
            # Special symbols MUST be escaped!
            # 0 means false, 1 means true
            # sub cmd_sql parameters: ($server_params, $cmd_sql, $tmp_file , $compress,  $comment_output)

            # send commant to SQL server
            cmd_sql($server_params, 'STOP SLAVE SQL_THREAD', 0 , 0 , 0);

            # Save Slave SQL server state
            cmd_sql($server_params, 'SHOW SLAVE STATUS\\G', $tmp_file , $compress , 1);

        }

        if ($user_option{"stopslave"}) {
            print "   >> Dumping all databases in '$tmp_file' WITH master data...\n";
            system("/usr/bin/mysqldump $server_params --single-transaction --master-data=1 -B -A | $compress >> $tmp_file");
        }
        else {
            print "   >> Dumping all databases in '$tmp_file' WITHOUT master data...\n";
            system("/usr/bin/mysqldump $server_params --single-transaction -B -A | $compress >> $tmp_file");
        }

        print "   >> Dumping all databases in '$tmp_file' finished.\n";

        # start slave
        if ($user_option{"stopslave"}) {
            cmd_sql($server_params, 'START SLAVE SQL_THREAD', 0 , 0 , 0);
        }

        # upload to ftp
        print "   >> Start uploading '$tmp_file' to ftp server.\n";
        ftp_upload( $ftp_host, $ftp_user, $ftp_pass, $ftp_dir, $tmp_file, $ftp_file, $keep_old_files);
        print "   >> Uploading '$tmp_file' finished.\n";

        # delete temporary file
        print "   >> Deleting temporary file: '$tmp_file'\n";
        unlink $tmp_file;
        print "   >> Temporary file '$tmp_file' deleted.\n";

    }    # END if allDBinOneFile

    # filePerDB
    else {

        # stop slave
        if ($user_option{"stopslave"}) {

            # send commant to SQL server
            cmd_sql($server_params, 'STOP SLAVE SQL_THREAD', 0 , 0 , 0);
        }

        # get list of databses
        my $databases_to_backup = $dbh->prepare('show databases');
        $databases_to_backup->execute();

        while ( my $row = $databases_to_backup->fetchrow_arrayref ) {
            push @databases, @$row;
        }

        # exclude non-user db
        for (@databases) {

            next if $_ =~ /^\#/;
            next if $_ =~ /mysql/;
            next if $_ =~ /performance_schema/;
            next if $_ =~ /test/;
            next if $_ =~ /information_schema/;
            push( @databases_to_backup, $_ );

        }

        for (@databases_to_backup) {

            $ftp_file = "$hostname-"."$db_name_prefix-"."$_" . ".$time" . ".sql.bz2";
            $tmp_file = "$tmp_path" . "$ftp_file";

            # Save Slave SQL server state
            if ($user_option{"stopslave"}) {

                # Save Slave SQL server state
                cmd_sql($server_params, 'SHOW SLAVE STATUS\\G', $tmp_file , $compress , 1);

            }

            # start dumping

            if ($user_option{"stopslave"}) {
                print "   >> Dumping database '$_' in $tmp_file WITH master data...\n";
                system("/usr/bin/mysqldump $server_params --single-transaction --master-data=1 -B $_ | $compress >> $tmp_file");
            }
            else {
                print "   >> Dumping database '$_' in $tmp_file WITHOUT master data...\n";
                system("/usr/bin/mysqldump $server_params --single-transaction -B $_ | $compress >> $tmp_file");
            }

            print "   >> Dumping database '$_' in $tmp_file finished.\n";

            # upload to ftp
            print "   >> Start uploading '$tmp_file' to ftp server.\n";
            ftp_upload( $ftp_host, $ftp_user, $ftp_pass, $ftp_dir, $tmp_file, $ftp_file, $keep_old_files);
            print "   >> Uploading '$tmp_file' finished.\n";

            # delete temporary file
            print "   >> Deleting temporary file: '$tmp_file'\n";
            unlink $tmp_file;
            print "   >> Temporary file '$tmp_file' deleted.\n";

        }

        # start slave
        if ($user_option{"stopslave"}) {
            cmd_sql($server_params, 'START SLAVE SQL_THREAD', 0 , 0 , 0);
        }


    }    # END filePerDB

}    # END MAIN

sub cmd_sql {

    # get variables
    my ( $server_params, $cmd_sql, $tmp_file , $compress, $comment_output) = @_;

    if ($tmp_file) {
        print "   >> Start executing SQL Command: '$cmd_sql' and saving output to '$tmp_file'\n";
        system("/usr/bin/mysql $server_params -e \"$cmd_sql\" > $tmp_file");
        print "   >> Executing SQL Command: '$cmd_sql' and saving output to '$tmp_file' finished\n";

        if ($comment_output) {
            print "   >> Start commenting '$tmp_file'\n";
            system("/bin/sed -i 's/^/#/' $tmp_file");
            print "   >> Commenting '$tmp_file' finished\n";
        }

        # Check compressor
        if ( !-f "$compress" ) {
            print STDERR "not found compress variable in call of cmd_sql function\n";
            exit(1);
        }

        system("mv $tmp_file \"$tmp_file.tmp\"");
        system("$compress \"$tmp_file.tmp\"");
        system("mv \"$tmp_file.tmp.bz2\" $tmp_file");

    }

    else {
        print "   >> Start executing SQL Command: '$cmd_sql'\n";
        system("/usr/bin/mysql $server_params -e \"$cmd_sql\"");
        print "   >> Executing SQL Command: '$cmd_sql' finished\n";
    }
}

sub ftp_upload {

    # get variables
    my ( $ftp_host, $ftp_user, $ftp_pass, $ftp_dir, $tmp_file, $ftp_file, $keep_old_files ) = @_;

    # set time for keeping files
    my $time     = time();
    my $del_time = $time - $keep_old_files;

    # connect to ftp host
    my $ftp = Net::FTP->new( "$ftp_host", Debug => 0 )
      or die "Cannot connect to $ftp_host: $@";

    # send username and password
    $ftp->login( $ftp_user, $ftp_pass ) or die "Cannot login ", $ftp->message;

    # switch to binary
    $ftp->binary();

    # make directory if not exists
    $ftp->mkdir($ftp_dir);

    # upload file
    $ftp->put( "$tmp_file", "$ftp_dir$ftp_file" );

    # get list of files
    my @list = $ftp->ls($ftp_dir);

    # delete all files older that $del_time
    for (@list) {
        next if $_ !~ /$_/;
        my $date_mod = $ftp->mdtm($_);

        if ( $date_mod < $del_time ) {
            print "$del_time\t$date_mod\t$_\n";
            $ftp->delete($_);
        }
    }

    # logout
    $ftp->quit;

}    # END ftp upload


sub parseCLIArgs
{
        my ($stopslave, $allDBinOneFile) = @_;

        my %user_option;

        # Cheking 'StopSlave' option
        if ( $stopslave eq 'true' ) {
           $user_option{"stopslave"} = 1;
           print "   >> User option 'Stop Slave SQL server' WILL be executed, master status and master data WILL BE saved\n";
        }
        else {
            if (($stopslave eq 'false') or ($stopslave eq '')) {
                $user_option{"stopslave"} = 0;
                print "   >> User option 'Stop Slave SQL server' WILL NOT be executed, master status and master data WILL NOT BE saved\n";
            }
            else {
                print STDERR "Invalid command line arguments supplied for option 'stopslave'.";
                showHelp();
                exit(1);
            }
        }

        # Cheking 'All Databases in One file' option
        if ( $allDBinOneFile eq 'true' ) {
           $user_option{"allDBinOneFile"} = 1;
           print "   >> User option 'All Databases in One file' WILL be executed\n";
        }
        else {
            if (($allDBinOneFile eq 'false') or ($allDBinOneFile eq '')) {
                $user_option{"allDBinOneFile"} = 0;
                print "   >> User option 'All Databases in One file' WILL NOT be executed\n";
            }
            else {
                print STDERR "Invalid command line arguments supplied for option 'allDBinOneFile'.";
                showHelp();
                exit(1);
            }
        }

        return %user_option;


} # END sub parseCLIArgs


sub showHelp
{
        my @showHelpMsg =
        (
                "USAGE:",
                "    -s --stopslave       To execute Stop Slave before dumping. Default: false . Example: '--stopslave=true'",
                "    -a --alldbinonefile  Backup all DBs in one file. Default: false . Example: '--alldbinonefile=true'",
                "    -h --help            Display help message (this).",
                "",
        );

        print join("\n", @showHelpMsg);
} # END sub showHelp
