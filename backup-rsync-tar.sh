#!/bin/bash
###################################
# Backup remote dir by rsync to a #
# local folder and theh tar it.   #
# Better user a  non-root user.   #
#                                 #
# ztodorov@neterra.net            #
# ver 0.07                        #
###################################

# changelog
# ver 0.07 - 20.12.2016 - corrected tar command to work with -C option
# ver 0.06 - 19.12.2016 - added -C option to tar to avoid "Removing leading `/' from member names" message
# ver 0.05 - added NICE_LOCAL and NICE_REMOTE options

# Usage
function usage() {
  echo "Usage: ./backup-rsync-tar.sh --pid=/tmp/examplepid.pid --limit=50000 --host=192.0.2.1 --user=exampleuser --key=/root/.ssh/backupkey --remotepath=/mnt/very_important_dir --localpath=/mnt/exampleRsyncStorage --excludelist=/root/excludelist.txt --backupprefix=backup-example --backupdir=/mnt/backups  --daystokeep=7"
  echo "--pid          >>>>   path and name of the pid file"
  echo "--limit         >>>>   set bandwidth limit in Kbit/s - 50000 is 50megabits or ~6megabytes per second"
  echo "--host         >>>>   remote host which we are going to backup"
  echo "--user         >>>>   remote user which we are using on remote host"
  echo "--key          >>>>   ssh key we are using for authentication"
  echo "--remotepath      >>>>   path to directory on remote host which we are going to backup"
  echo "--localpath         >>>>   path to local directory where we are going to rsync data"
  echo "--excludelist         >>>>   path to text file where there is a list of files or directories for excludeing from rsync"
  echo "--backupprefix  >>>>   backup file name prefix"
  echo "--backupdir       >>>>   directory where tar backups are saved"
  echo "--daystokeep     >>>>   how many days we are going to keep backup files"
  exit 1
}

# rsync options
NICE_LOCAL='nice -n 19 ionice -c 3'
NICE_REMOTE="${NICE_LOCAL} rsync"

# Get command line parameters
for i in "$@"
do
case $i in

    --pid=*)
    BACKUPPID="${i#*=}"
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
    --excludelist=*)
    EXCLUDELIST="${i#*=}"
    shift # past argument=value
    ;;
    --backupprefix=*)
    BACKUPPREFIX="${i#*=}"
    shift # past argument=value
    ;;
    --backupdir=*)
    BACKUPDIR="${i#*=}"
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
if [[ -z "${BACKUPPID}" ]]; then
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

if [[ -z "${BACKUPPREFIX}" ]]; then
  echo "ERROR: Local backup filename prefix is not set"
  usage
fi

if [[ -z "${BACKUPDIR}" ]]; then
  echo "ERROR: Local backup directory is not set"
  usage
fi

if [[ -z "${DAYSTOKEEP}" ]]; then
  echo "ERROR: Days to keep backup is not set"
  usage
fi

# Check for running backups or stalled(existing) pid file
if [[ -e "${BACKUPPID}" ]]; then
  echo "ERROR: Stalled(existing) pid file found - script is still running or been murdered!"
  exit 1
fi

# Write pid file
echo "Wrinting PID file ${BACKUPPID}"
echo $$ >${BACKUPPID} || exit $?

# Get current time
NOW=$(date +"%d-%m-%Y--%H-%M-%S") || exit $?

# Start backup and exit with error if something fails (pid file is not removed). Using lowest I/O priority.
if [[ -z "${EXCLUDELIST}" ]]; then
  echo "Start backup at $NOW. Backuping ${REMOTEPATH} at ${REMOTEHOST} with user ${REMOTEUSER} and key ${REMOTEKEY} to local folder ${LOCALPATH} with bandwidth limit ${KBLIMIT}"
  ${NICE_LOCAL} rsync -avz --delete --bwlimit=${KBLIMIT} --rsync-path="${NICE_REMOTE}" -e "ssh -i ${REMOTEKEY}" ${REMOTEUSER}@${REMOTEHOST}:${REMOTEPATH}/ ${LOCALPATH}/ || exit $?
else
  echo "Start backup at $NOW. Backuping ${REMOTEPATH} at ${REMOTEHOST} with user ${REMOTEUSER} and key ${REMOTEKEY} to local folder ${LOCALPATH} with bandwidth limit ${KBLIMIT} excluding list from ${EXCLUDELIST}"
  ${NICE_LOCAL} rsync -avz --delete --bwlimit=${KBLIMIT} --rsync-path="${NICE_REMOTE}" -e "ssh -i ${REMOTEKEY}" --exclude-from=${EXCLUDELIST} ${REMOTEUSER}@${REMOTEHOST}:${REMOTEPATH}/ ${LOCALPATH}/ || exit $?
fi

echo "Start tarring to ${BACKUPDIR}/${BACKUPPREFIX}-${NOW}.tar"
${NICE_LOCAL} tar cf ${BACKUPDIR}/${BACKUPPREFIX}-${NOW}.tar -C ${LOCALPATH} . || exit $?

# Delete old backups
echo "Deleting backup files in ${BACKUPDIR} older than ${DAYSTOKEEP} days"
find  ${BACKUPDIR}/${BACKUPPREFIX}-* -mtime +${DAYSTOKEEP} -type f -print || exit $?
find  ${BACKUPDIR}/${BACKUPPREFIX}-* -mtime +${DAYSTOKEEP} -type f -delete || exit $?

# Remove pid file
echo "Remove PID file"
rm ${BACKUPPID} || exit $?
