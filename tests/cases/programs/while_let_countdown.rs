// stdout: 5
// stdout: 4
// stdout: 3
// stdout: 2
// stdout: 1
// exit: 0

fn main() {
    let mut opt: Option<i32> = Option::<i32>::Some(5);
    while let Some(n) = opt {
        println!("{}", n);
        if n <= 1 {
            opt = Option::<i32>::None;
        } else {
            opt = Option::<i32>::Some(n - 1);
        }
    }
    exit(0);
}
