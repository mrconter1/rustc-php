// exit: 0
// stdout: 3
// stdout: done
fn main() {
    let opt: Option<i32> = Option::<i32>::Some(3);
    if let Some(n) = opt {
        println!("{}", n);
    }
    let opt2: Option<i32> = Option::<i32>::None;
    if let Some(_) = opt2 {
        println!("bad");
    }
    println!("done");
}
