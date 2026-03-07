// exit: 0
// stdout: 3
// stdout: 2
// stdout: 1
fn main() {
    let mut opt: Option<i32> = Option::<i32>::Some(3);
    while let Some(n) = opt {
        println!("{}", n);
        if n <= 1 {
            opt = Option::<i32>::None;
        } else {
            opt = Option::<i32>::Some(n - 1);
        }
    }
}
