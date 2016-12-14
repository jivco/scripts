#!/bin/bash
############################
#  Backup remote dir by ssh to a #
# local tar file. Better user a       #
# non-root user.                           #
#                                                  #
# ztodorov@neterra.net              #
# ver 0.01                                    #
###########################


# Usage
function usage() {
  echo "Usage: ./backup-remote-ssh.sh --pid=/tmp/examplepid.pid --limit=50000 --host=192.0.2.1 --user=exampleuser --key=/root/.ssh/backupkey --remotepath=/mnt/very_important_dir --localpath=/mnt/exampleBackupStorage --filename=backup-example --daystokeep=7"
  echo "--pid          >>>>   path and name of the pid file"
  echo "--limit         >>>>   set bandwidth limit in Kbit/s - 50000 is 50megabits or ~6megabytes per second"
  echo "--host         >>>>   remote host which we are going to backup"
  echo "--user         >>>>   remote user which we are using on remote host"
  echo "--key          >>>>   ssh key we are using for authentication"
  echo "--remotepath   >>>>   path to directory on remote host which we are going to backup"
  echo "--localpath    >>>>   path to local directory where we are going to store backup file"
  echo "--filename     >>>>   backup file name prefix"
  echo "--daystokeep   >>>>   how many days we are going to keep backup files"
  exit 1
}

# Get command line parameters
for i in "$@"
do
case $i in

    --pid=*)
    BACKUPREMOTESSHPID="${i#*=}"
    shift # past argument=value
    ;;
    --limit=*)
    KBLIMIT="${i#*=}"
    shift # past argument=value
    ;;
    --host=*)
    REMOTEHOST="${i#*=}"
    shift # past argument=value
    ;;
    --user=*)
    REMOTEUSER="${i#*=}"
    shift # past argument=value
    ;;
    --key=*)
    REMOTEKEY="${i#*=}"
    shift # past argument=value
    ;;
    --remotepath=*)
    REMOTEPATH="${i#*=}"
    shift # past argument=value
    ;;
    --localpath=*)
    LOCALPATH="${i#*=}"
    shift # past argument=value
    ;;
    --filename=*)
    FILENAME="${i#*=}"
    shift # past argument=value
    ;;
    --daystokeep=*)
    DAYSTOKEEP="${i#*=}"
    shift # past argument=value
    ;;
    --default)
    DEFAULT=YES
    shift # past argument with no value
    ;;
    *)
            # unknown option
    ;;
esac
done

# Check for all needed command line parameters
if [[ -z "${BACKUPREMOTESSHPID}" ]]; then
  echo "ERROR: PID file is not set"
  usage
fi

if [[ -z "${KBLIMIT}" ]]; then
  echo "ERROR: Bandwidth limit is not set"
  usage
fi

if [[ -z "${REMOTEHOST}" ]]; then
  echo "ERROR: Host is not set"
  usage
fi

if [[ -z "${REMOTEUSER}" ]]; then
  echo "ERROR: User is not set"
  usage
fi

if [[ -z "${REMOTEKEY}" ]]; then
  echo "ERROR: Key is not set"
  usage
fi

if [ ! -r "$REMOTEKEY" ]; then
  echo "ERROR: Cannot access key"
  exit 1
fi

if [[ -z "${REMOTEPATH}" ]]; then
  echo "ERROR: Remote path is not set"
  usage
fi

if [[ -z "${LOCALPATH}" ]]; then
  echo "ERROR: Local path is not set"
  usage
fi

if [[ -z "${FILENAME}" ]]; then
  echo "ERROR: Local backup filename prefix is not set"
  usage
fi

if [[ -z "${DAYSTOKEEP}" ]]; then
  echo "ERROR: Days to keep backup is not set"
  usage
fi

# Check for running backups or stalled(existing) pid file
if [[ -e "${BACKUPREMOTESSHPID}" ]]; then
  echo "ERROR: Stalled(existing) pid file found - script is still running or been murdered!"
  exit 1
fi

# Write pid file
echo "Wrinting PID file ${BACKUPREMOTESSHPID}"
echo $$ >${BACKUPREMOTESSHPID} || exit $?

# Get current time
NOW=$(date +"%m-%d-%Y--%H-%M-%S") || exit $?

# Start backup and exit with error if something fails (pid file is not removed)
echo "Start backup at $NOW. Backuping ${REMOTEPATH} at ${REMOTEHOST} with user ${REMOTEUSER} and key ${REMOTEKEY} to local file ${LOCALPATH}/${FILENAME}-${NOW}.tar with bandwidth limit ${KBLIMIT}"
ssh -i ${REMOTEKEY} -l ${KBLIMIT} ${REMOTEUSER}@${REMOTEHOST} "nice -n 19 ionice -c 3 tar -cf - ${REMOTEPATH}" > ${LOCALPATH}/${FILENAME}-${NOW}.tar || exit $?

# Delete old backups
echo "Deleting backup files ${LOCALPATH}/${FILENAME} older than ${DAYSTOKEEP} days"
find  ${LOCALPATH}/${FILENAME}-* -mtime +${DAYSTOKEEP} -type f -print || exit $?
find  ${LOCALPATH}/${FILENAME}-* -mtime +${DAYSTOKEEP} -type f -delete || exit $?

# Remove pid file
echo "Remove PID file"
rm ${BACKUPREMOTESSHPID} || exit $?
